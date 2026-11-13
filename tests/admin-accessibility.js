#!/usr/bin/env node
/**
 * Accessibility audit for the plugin's own admin screens.
 *
 * Requires the disposable WordPress site plus a cookie fixture generated for it
 * (see the repository README): never run this against a real site. Playwright
 * and axe-core must be installed where Node can resolve them.
 *
 *   node tests/admin-accessibility.js --fixture /path/cookies.json \
 *     [--base-url http://127.0.0.1:8088] [--form <id>] [--submission <id>] [--template <id>]
 */
const fs = require('fs');
const path = require('path');

function arg(name, fallback) {
  const index = process.argv.indexOf(`--${name}`);
  return index > -1 && process.argv[index + 1] ? process.argv[index + 1] : fallback;
}

const fixturePath = arg('fixture');
if (!fixturePath) {
  console.error('Pass --fixture <cookies.json>');
  process.exit(2);
}
const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
const baseUrl = arg('base-url', fixture.base_url).replace(/\/$/, '');
const formId = arg('form', '');
const submissionId = arg('submission', '');
const templateId = arg('template', '');

const axePath = require.resolve('axe-core/axe.min.js');
const { chromium } = require('playwright');

const pages = [
  ['Forms overview', `${baseUrl}/wp-admin/admin.php?page=advanced-divi-forms`],
];
if (formId) {
  pages.push(['Submissions list', `${baseUrl}/wp-admin/admin.php?page=advanced-divi-forms&form_id=${formId}`]);
  pages.push(['Submissions filter focus', `${baseUrl}/wp-admin/admin.php?page=advanced-divi-forms&form_id=${formId}&adf_view=trash`]);
}
if (formId && submissionId) {
  pages.push(['Submission detail', `${baseUrl}/wp-admin/admin.php?page=advanced-divi-forms&form_id=${formId}&submission_id=${submissionId}`]);
}
pages.push(['Mail templates', `${baseUrl}/wp-admin/edit.php?post_type=adf_mail_template`]);
if (templateId) {
  pages.push(['Mail template editor', `${baseUrl}/wp-admin/post.php?post=${templateId}&action=edit`]);
}
pages.push(['Privacy & retention', `${baseUrl}/wp-admin/admin.php?page=adf-privacy`]);
pages.push(['Plugin updates', `${baseUrl}/wp-admin/admin.php?page=adf-updates`]);

const axeOptions = {
  runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'] },
  resultTypes: ['violations'],
};

// WordPress core chrome outside the plugin's own screens. Core markup is not
// something this plugin can fix, so its findings are reported but do not fail the
// run. Everything inside #wpbody-content stays fully audited.
const CORE_CHROME = ['#wpadminbar', '#adminmenu', '#collapse-button', '#wpfooter', '#screen-meta', '#screen-meta-links'];

function isCoreChrome(node) {
  return node.target.some((target) => {
    const selector = Array.isArray(target) ? target.join(' ') : String(target);
    return CORE_CHROME.some((core) => selector.startsWith(core));
  });
}

function splitByOwnership(violations) {
  const relevant = [];
  const core = [];
  for (const violation of violations) {
    const nodes = violation.nodes.filter((node) => !isCoreChrome(node));
    if (nodes.length) {
      relevant.push({ ...violation, nodes });
    } else {
      core.push(violation);
    }
  }
  return { relevant, core };
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  await context.addCookies(fixture.cookies);
  const page = await context.newPage();
  let failed = 0;
  const report = [];

  for (const [label, url] of pages) {
    const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
    if (!response || response.status() >= 400) {
      console.error(`FAIL ${label}: HTTP ${response ? response.status() : 'no response'} (${url})`);
      failed += 1;
      continue;
    }
    if (await page.locator('body.login, #loginform').count()) {
      console.error(`FAIL ${label}: the fixture cookies were not accepted`);
      failed += 1;
      continue;
    }
    await page.addScriptTag({ path: axePath });
    const result = await page.evaluate(async (options) => window.axe.run(document, options), axeOptions);
    const { relevant, core } = splitByOwnership(result.violations);
    const blocking = relevant.filter((violation) => ['critical', 'serious'].includes(violation.impact));
    const minor = relevant.filter((violation) => !['critical', 'serious'].includes(violation.impact));
    report.push({ label, url, blocking, minor, core });
    if (blocking.length) {
      failed += 1;
      console.error(`FAIL ${label}: ${blocking.length} serious/critical violation(s)`);
      for (const violation of blocking) {
        console.error(`  - ${violation.id} (${violation.impact}): ${violation.help}`);
        for (const node of violation.nodes.slice(0, 4)) {
          console.error(`      ${node.target.join(' ')} :: ${(node.failureSummary || '').split('\n')[1] || ''}`);
        }
      }
    } else {
      console.log(`PASS ${label} (${minor.length} minor, ${core.length} core-only)`);
    }
    for (const violation of minor) {
      console.log(`  minor ${label}: ${violation.id} (${violation.impact}) x${violation.nodes.length}`);
    }
    for (const violation of core) {
      console.log(`  core ${label}: ${violation.id} (${violation.impact}) x${violation.nodes.length} — WordPress core chrome, not this plugin`);
    }
  }

  const outPath = arg('report', '');
  if (outPath) {
    fs.writeFileSync(outPath, JSON.stringify(report, null, 2));
    console.log(`Report written to ${outPath}`);
  }

  await browser.close();
  console.log(failed === 0 ? `PASS: no serious or critical accessibility violations on ${pages.length} screens` : `FAIL: ${failed} screen(s) with serious/critical violations`);
  process.exit(failed === 0 ? 0 : 1);
})();
