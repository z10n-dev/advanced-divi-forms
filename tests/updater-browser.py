"""Real browser and live anonymous GitHub check in disposable WordPress only."""
import json
import os
import re
import subprocess
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

fixture = json.loads(Path(sys.argv[1]).read_text())
expected = json.loads(subprocess.check_output(['curl', '-fsSL', 'https://api.github.com/repos/z10n-dev/advanced-divi-forms/releases/latest']))['tag_name'].removeprefix('v')
with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=os.environ.get('ADF_CHROMIUM'))
    context = browser.new_context(viewport={'width': 1280, 'height': 900})
    context.add_cookies(fixture['cookies'])
    page = context.new_page()
    page.goto('http://127.0.0.1:8767/wp-admin/admin.php?page=adf-updates', wait_until='networkidle')
    expect(page.get_by_role('heading', name='Plugin Updates', exact=True)).to_be_visible()
    latest = page.get_by_role('row', name=re.compile('Latest stable version')).locator('td')
    expect(latest).to_have_text('0.0.1')
    forbidden_get = context.request.get('http://127.0.0.1:8767/wp-admin/admin-post.php?action=adf_check_updates')
    assert forbidden_get.status == 405
    page.get_by_role('button', name='Check GitHub now (bypass cache)', exact=True).click()
    expect(latest).to_have_text(expected, timeout=25000)
    expect(page.get_by_role('row', name=re.compile('Last HTTP status')).locator('td')).to_have_text('200')
    assert page.get_by_role('row', name=re.compile('Last successful check')).locator('td').inner_text() != '—'
    expect(page.locator('.notice-error')).to_have_count(0)
    for width, name in [(1280, 'desktop'), (768, 'tablet'), (390, 'mobile')]:
        page.set_viewport_size({'width': width, 'height': 900})
        expect(page.get_by_role('button', name='Check GitHub now (bypass cache)', exact=True)).to_be_visible()
        assert page.locator('body').evaluate('e => e.scrollWidth <= innerWidth + 2')
        page.screenshot(path=str(Path(sys.argv[1]).parent / f'updater-{name}.png'), full_page=True)
    browser.close()
print(f'PASS: live anonymous release {expected}, stale-cache bypass via protected POST, diagnostics, desktop/mobile and GET refusal')
