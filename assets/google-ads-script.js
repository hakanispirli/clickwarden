/**
 * ClickWarden – Google Ads IP exclusion sync
 * https://github.com/hakanispirli/clickwarden
 *
 * Setup: Google Ads → Tools → Bulk actions → Scripts → + → paste this code,
 * click "Authorize", try it with "Preview", then set the frequency to "Hourly".
 *
 * - The campaigns to manage are set in WordPress (ClickWarden → Google Ads).
 * - Only IPs added by this script are ever removed; your manual exclusions are never touched.
 * - The limit of 500 excluded IPs per campaign is respected.
 */

const CONFIG = {
  ENDPOINT: '{{ENDPOINT}}',
  TOKEN: '{{TOKEN}}',
  // Set to true to only log what would change, without changing anything.
  DRY_RUN: false,
};

function main() {
  const preview = AdsApp.getExecutionInfo().isPreview();
  const data = callPlugin('list', {});
  const limit = data.limit || 500;
  const block = data.block.map(normalizeIp);
  const unblock = new Set(data.unblock.map(normalizeIp));

  const campaigns = findCampaigns(data.campaigns);
  const missing = data.campaigns.filter((name) => !campaigns.some((c) => c.name === name));
  missing.forEach((name) => Logger.log('WARNING: campaign not found: "%s"', name));

  const existing = readExclusions(campaigns);
  // IPs already excluded somewhere before this run (by hand or earlier runs) are never
  // reported as script-managed, so a later unblock can not undo a manual exclusion.
  const preexisting = new Set();
  campaigns.forEach((c) => existing[c.id].forEach((_, ip) => preexisting.add(ip)));

  const removeOps = [];
  const removeMeta = [];
  const addOps = [];
  const addMeta = [];

  campaigns.forEach((campaign) => {
    const current = existing[campaign.id];
    const before = current.size;
    let adds = 0;
    let removes = 0;

    // 1) IPs the plugin no longer wants blocked (whitelisted, or pushed out of the top 500).
    current.forEach((resourceName, ip) => {
      if (unblock.has(ip)) {
        removeOps.push({ campaignCriterionOperation: { remove: resourceName } });
        removeMeta.push(ip);
        current.delete(ip);
        removes++;
      }
    });

    // 2) New suspicious IPs, most risky first, while the campaign has room.
    let free = limit - current.size;
    block.forEach((ip) => {
      if (free <= 0 || current.has(ip)) {
        return;
      }
      addOps.push({
        campaignCriterionOperation: {
          create: { campaign: campaign.resourceName, negative: true, ipBlock: { ipAddress: ip } },
        },
      });
      addMeta.push(ip);
      adds++;
      free--;
    });

    Logger.log('%s: %s excluded IPs, adding %s, removing %s, free slots left %s',
      campaign.name, before, adds, removes, Math.max(free, 0));
  });

  if (CONFIG.DRY_RUN) {
    Logger.log('DRY_RUN: nothing changed. Would add: %s | Would remove: %s', addMeta.join(', '), removeMeta.join(', '));
    return;
  }

  const errors = [];
  const removed = applyAll(removeOps, removeMeta, errors);
  const added = applyAll(addOps, addMeta, errors);

  Logger.log('Done. Added: %s, removed: %s, errors: %s', added.length, removed.length, errors.length);
  errors.forEach((e) => Logger.log('ERROR: %s', e));

  // Preview runs do not change the account, so the plugin must not record them.
  if (!preview) {
    callPlugin('ack', {
      added: unique(added).filter((ip) => !preexisting.has(ip)),
      removed: unique(removed),
      campaigns: campaigns.map((c) => c.name),
      missing: missing,
      errors: errors.slice(0, 10),
    });
  } else {
    Logger.log('Preview mode: nothing was reported to WordPress.');
  }
}

function callPlugin(route, payload) {
  const response = UrlFetchApp.fetch(CONFIG.ENDPOINT + route, {
    method: 'post',
    contentType: 'application/json',
    headers: { 'X-ClickWarden-Token': CONFIG.TOKEN },
    payload: JSON.stringify(payload),
    muteHttpExceptions: true,
  });
  const code = response.getResponseCode();
  if (code !== 200) {
    throw new Error('WordPress answered ' + code + ': ' + response.getContentText().slice(0, 300));
  }
  return JSON.parse(response.getContentText());
}

function findCampaigns(names) {
  if (!names.length) {
    throw new Error('No campaigns configured in WordPress (ClickWarden → Google Ads).');
  }
  const quoted = names.map((n) => "'" + n.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'").join(', ');
  const rows = AdsApp.search(
    'SELECT campaign.id, campaign.name, campaign.resource_name FROM campaign ' +
    'WHERE campaign.name IN (' + quoted + ") AND campaign.status != 'REMOVED'"
  );
  const found = [];
  while (rows.hasNext()) {
    const row = rows.next();
    found.push({ id: String(row.campaign.id), name: row.campaign.name, resourceName: row.campaign.resourceName });
  }
  return found;
}

function readExclusions(campaigns) {
  const map = {};
  campaigns.forEach((c) => { map[c.id] = new Map(); });
  if (!campaigns.length) {
    return map;
  }
  const rows = AdsApp.search(
    'SELECT campaign.id, campaign_criterion.resource_name, campaign_criterion.ip_block.ip_address ' +
    "FROM campaign_criterion WHERE campaign_criterion.type = 'IP_BLOCK' " +
    'AND campaign.id IN (' + campaigns.map((c) => c.id).join(', ') + ')'
  );
  while (rows.hasNext()) {
    const row = rows.next();
    const ip = normalizeIp(row.campaignCriterion.ipBlock.ipAddress);
    map[String(row.campaign.id)].set(ip, row.campaignCriterion.resourceName);
  }
  return map;
}

function applyAll(operations, meta, errors) {
  const done = [];
  for (let i = 0; i < operations.length; i += 1000) {
    const results = AdsApp.mutateAll(operations.slice(i, i + 1000), { partialFailure: true });
    results.forEach((result, j) => {
      if (result.isSuccessful()) {
        done.push(meta[i + j]);
      } else {
        errors.push(meta[i + j] + ': ' + result.getErrorMessages().join('; '));
      }
    });
  }
  return done;
}

function normalizeIp(ip) {
  return String(ip).trim().toLowerCase();
}

function unique(list) {
  return Array.from(new Set(list));
}
