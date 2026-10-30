"""Historical label UI and real HTTP XLSX with duplicate/renamed/legacy labels."""
import json
import os
import sys
from pathlib import Path
from openpyxl import load_workbook
from playwright.sync_api import sync_playwright, expect

path = Path(sys.argv[1])
f = json.loads(path.read_text())
url = f'http://127.0.0.1:8767/wp-admin/admin.php?page=advanced-divi-forms&form_id={f["form"]}'
with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=os.environ.get('ADF_CHROMIUM'))
    context = browser.new_context(viewport={'width': 1280, 'height': 1000}, accept_downloads=True)
    context.add_cookies(f['cookies'])
    page = context.new_page()
    page.goto(url, wait_until='domcontentloaded')
    row = page.locator(f'tr[data-adf-submission="{f["old"]}"]')
    expect(row.locator('.adf-preview')).to_contain_text('Shared label [first]')
    expect(row.locator('.adf-preview')).to_contain_text('Shared label [second]')
    expect(page.locator(f'tr[data-adf-submission="{f["new"]}"] .adf-preview')).to_contain_text('Renamed field [first]')
    expect(page.locator(f'tr[data-adf-submission="{f["legacy"]}"] .adf-preview')).to_contain_text('first: Legacy')
    row.get_by_text('Quick Edit', exact=True).click()
    row.get_by_label('Status', exact=True).select_option('1')
    row.get_by_label('Internal note', exact=True).fill('Retain historical label')
    row.get_by_role('button', name='Save changes', exact=True).click()
    page.wait_for_url('**adf_updated=1*')
    expect(row.locator('.adf-preview')).to_contain_text('Shared label [first]')
    row.locator('td a').first.click()
    expect(page.locator('th[scope="row"]')).to_have_text(['Shared label [first]', 'Shared label [second]', 'plain'])
    expect(page.locator('.adf-submission-detail table tbody tr td')).to_have_text(['Ada', '00123', '=1+1'])
    page.get_by_role('link', name='Back to submissions', exact=False).click()
    with page.expect_download() as waiting:
        page.get_by_role('button', name='Export all to Excel', exact=True).click()
    destination = path.parent / 'historical-labels-browser.xlsx'
    waiting.value.save_as(destination)
    sheet = load_workbook(destination).active
    headers = [c.value for c in sheet[1]]
    assert headers[4:7] == ['first', 'second', 'plain'], headers
    assert headers[7:] == ['Field label [first]', 'Field label [second]', 'Field label [plain]']
    rows = {int(r[0].value): r for r in list(sheet.rows)[1:]}
    assert set(rows) == {f['old'], f['new'], f['legacy']}
    old = rows[f['old']]
    assert [c.value for c in old[4:]] == ['Ada', '00123', '=1+1', 'Shared label', 'Shared label', 'plain']
    assert all(c.data_type == 's' for c in old[4:]), 'Values/labels must remain literal strings, never formulas'
    assert rows[f['new']][7].value == 'Renamed field'
    assert [c.value for c in rows[f['legacy']][7:]] == ['first', '', '']
    for width in [1280, 768, 390]:
        page.set_viewport_size({'width': width, 'height': 1000})
        assert page.locator('body').evaluate('e => e.scrollWidth <= innerWidth + 2')
        row.locator('.adf-preview').scroll_into_view_if_needed()
        page.screenshot(path=str(path.parent / f'historical-labels-{width}.png'))
    browser.close()
print('PASS: historical/renamed/duplicate labels plus IDs in list/detail and real HTTP XLSX; legacy fallback, immutable values, Quick Edit and three viewports')
