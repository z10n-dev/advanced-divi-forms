"""Real role matrix: read-only capability, denied direct actions, admin controls, privacy settings."""
import json
import os
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

path = Path(sys.argv[1])
f = json.loads(path.read_text())
base = 'http://127.0.0.1:8767'
list_url = f'{base}/wp-admin/admin.php?page=advanced-divi-forms&form_id={f["form"]}'


def context_for(browser, cookies):
    context = browser.new_context(viewport={'width': 1280, 'height': 1000})
    context.add_cookies(cookies)
    return context


with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=os.environ.get('ADF_CHROMIUM'))

    reader = context_for(browser, f['reader_cookies'])
    page = reader.new_page()
    page.goto(list_url, wait_until='domcontentloaded')
    expect(page.locator('.adf-submissions h1')).to_have_text('Capability matrix fixture')
    expect(page.locator(f'tr[data-adf-submission="{f["ids"][0]}"]')).to_be_visible()
    assert page.locator('#adf-bulk-form').count() == 0, 'Read-only access must not receive bulk controls'
    assert page.get_by_role('button', name='Export all to Excel').count() == 0, 'Read-only access must not receive export controls'
    assert page.locator('.adf-quick-edit').count() == 0, 'Read-only access must not receive edit controls'
    denied_export = reader.request.post(
        base + '/wp-admin/admin-post.php',
        form={'action': 'adf_export_submissions', 'form_id': str(f['form']), '_wpnonce': 'invalid', 'scope': 'all'},
    )
    assert denied_export.status == 403, f'Direct export must be 403, got {denied_export.status}'
    denied_action = reader.request.post(
        base + '/wp-admin/admin-post.php',
        form={'action': 'adf_manage_submissions', 'form_id': str(f['form']), '_wpnonce': 'invalid', 'operation': 'trash', 'submission_ids[]': str(f['ids'][0])},
    )
    assert denied_action.status == 403, f'Direct management must be 403, got {denied_action.status}'
    denied_privacy = reader.request.get(base + '/wp-admin/admin.php?page=adf-privacy')
    assert denied_privacy.status == 403, f'Read-only access must not read deletion policy, got {denied_privacy.status}'
    reader.close()

    admin = context_for(browser, f['admin_cookies'])
    page = admin.new_page()
    page.goto(list_url, wait_until='domcontentloaded')
    expect(page.locator('#adf-bulk-form')).to_be_visible()
    expect(page.get_by_role('button', name='Export all to Excel')).to_be_visible()
    expect(page.locator('.adf-quick-edit').first).to_be_attached()
    page.goto(base + '/wp-admin/admin.php?page=adf-privacy', wait_until='domcontentloaded')
    expect(page.locator('h1')).to_have_text('Privacy & Retention')
    expect(page.get_by_text('Disabled — nothing is deleted automatically')).to_be_visible()
    page.get_by_role('checkbox').check()
    page.locator('#adf-retention-days').fill('30')
    page.get_by_role('button', name='Save privacy settings').click()
    page.wait_for_url('**adf_privacy=saved*')
    expect(page.get_by_text('Enabled after 30 days')).to_be_visible()
    page.get_by_role('checkbox').uncheck()
    page.get_by_role('button', name='Save privacy settings').click()
    page.wait_for_url('**adf_privacy=saved*')
    expect(page.get_by_text('Disabled — nothing is deleted automatically')).to_be_visible()
    for width in [1280, 768, 390]:
        page.set_viewport_size({'width': width, 'height': 1000})
        assert page.locator('body').evaluate('e => e.scrollWidth <= innerWidth + 2'), f'Privacy page overflows at {width}px'
        page.screenshot(path=str(path.parent / f'privacy-settings-{width}.png'))
    admin.close()
    browser.close()

print('PASS: read-only capability sees answers but no export/manage controls, direct handlers return 403, admin keeps full controls, opt-in retention toggles explicit, three viewports')
