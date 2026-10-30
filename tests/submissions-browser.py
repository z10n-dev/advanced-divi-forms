"""Optional real-browser acceptance test against a disposable WordPress.

Requires playwright, openpyxl and a locally generated fixture JSON containing
form, ids and ephemeral admin cookies. Never run against a production site.
"""
import argparse
import json
import os
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

parser = argparse.ArgumentParser()
parser.add_argument("fixture")
parser.add_argument("--base-url", default="http://127.0.0.1:8767")
args = parser.parse_args()
fixture = json.loads(Path(args.fixture).read_text())
url = f"{args.base_url}/wp-admin/admin.php?page=advanced-divi-forms&form_id={fixture['form']}"
with sync_playwright() as p:
    options = {"headless": True}
    if os.environ.get("ADF_CHROMIUM"):
        options["executable_path"] = os.environ["ADF_CHROMIUM"]
    browser = p.chromium.launch(**options)
    context = browser.new_context(accept_downloads=True)
    context.add_cookies(fixture["cookies"])
    page = context.new_page()
    page.goto(url, wait_until="domcontentloaded")
    expect(page.get_by_role("heading", name="Submissions", exact=True)).to_be_visible()
    boxes = page.locator('input[form="adf-bulk-form"][name="submission_ids[]"]')
    expect(boxes).to_have_count(20)
    page.locator("#adf-select-all").check()
    assert boxes.evaluate_all("elements => elements.every(e => e.checked)")
    expect(page.locator("[data-adf-selection-count]")).to_have_text("20 selected")
    boxes.first.uncheck()
    assert page.locator("#adf-select-all").evaluate("e => e.indeterminate")
    page.locator("#adf-select-all").check()
    page.locator("#adf-select-all").uncheck()
    assert boxes.evaluate_all("elements => elements.every(e => !e.checked)")
    print("PASS: selection, select-all and indeterminate state in real Chromium")
    row = page.locator('[data-adf-submission]').first
    first_id = row.get_attribute('data-adf-submission')
    row.locator('summary').click()
    row.locator('textarea[name="note"]').fill("O'Brien C:\\work\nFollow up")
    row.locator('select[name="read"]').select_option('1')
    row.get_by_role('button', name='Save changes').click()
    expect(page.locator('.notice-success')).to_contain_text('Submission changes saved.')
    row = page.locator(f'[data-adf-submission="{first_id}"]')
    expect(row.locator('.adf-status')).to_have_text('Read')
    expect(row.locator('.adf-note-preview')).to_have_text("O'Brien C:\\work\nFollow up")
    expect(row.locator('.adf-preview')).to_contain_text('Test 22')
    row.locator('summary').click()
    row.locator('textarea[name="note"]').fill('Discard this edit')
    row.get_by_role('button', name='Cancel', exact=True).click()
    row.locator('summary').click()
    expect(row.locator('textarea[name="note"]')).to_have_value("O'Brien C:\\work\nFollow up")
    print('PASS: inline status/note save, persisted original values and cancel reset')
    boxes = page.locator('input[form="adf-bulk-form"][name="submission_ids[]"]')
    boxes.nth(0).check()
    boxes.nth(1).check()
    page.locator('#adf-bulk-operation').select_option('mark_unread')
    page.get_by_role('button', name='Apply', exact=True).click()
    expect(page.locator('[data-adf-submission]').nth(0).locator('.adf-status')).to_have_text('New')
    expect(page.locator('[data-adf-submission]').nth(1).locator('.adf-status')).to_have_text('New')
    page.goto(url + '&adf_paged=2', wait_until='domcontentloaded')
    expect(page.locator('[data-adf-submission]')).to_have_count(3)
    row = page.locator('[data-adf-submission]').first
    row.locator('summary').click()
    row.locator('textarea[name="note"]').fill('Second page note')
    row.get_by_role('button', name='Save changes').click()
    assert 'adf_paged=2' in page.url
    expect(page.locator('[data-adf-submission]').first.locator('.adf-note-preview')).to_have_text('Second page note')
    print('PASS: bulk unread and Quick Edit preserve pagination')
    page.set_viewport_size({'width': 390, 'height': 844})
    expect(page.get_by_role('button', name='Export all to Excel')).to_be_visible()
    assert page.locator('body').evaluate('e => e.scrollWidth <= window.innerWidth + 2')
    print('PASS: mobile viewport contains wide table in its scroll container')
    page.set_viewport_size({'width': 1200, 'height': 900})
    page.goto(url, wait_until='domcontentloaded')
    from openpyxl import load_workbook
    from io import BytesIO
    def read_download(download):
        assert download.suggested_filename.endswith('.xlsx')
        return load_workbook(BytesIO(Path(download.path()).read_bytes())).active
    with page.expect_download() as pending:
        page.get_by_role('button', name='Export all to Excel').click()
    sheet = read_download(pending.value)
    data = list(sheet.values)
    assert len(data) == 24, 'Export all must include all 23 submissions across pages'
    assert all(cell.data_type != 'f' for row in sheet for cell in row)
    header = data[0]
    assert all(dict(zip(header, r))['formula'] == '=1+1' for r in data[1:])
    assert all(dict(zip(header, r))['code'] == '00123' for r in data[1:])
    print('PASS: real HTTP XLSX download opens with openpyxl and includes every page')
    boxes = page.locator('input[form="adf-bulk-form"][name="submission_ids[]"]')
    boxes.nth(0).check()
    boxes.nth(1).check()
    page.locator('#adf-bulk-operation').select_option('export')
    with page.expect_download() as pending:
        page.get_by_role('button', name='Apply', exact=True).click()
    assert len(list(read_download(pending.value).values)) == 3
    print('PASS: selected Excel download includes exactly the two selected records')
    page.locator('#adf-bulk-operation').select_option('trash')
    page.get_by_role('button', name='Apply', exact=True).click()
    expect(page.get_by_role('link', name=f"Trash ({len(fixture.get('trash_ids', [])) + 2})", exact=True)).to_be_visible()
    expect(page.locator('.notice-success')).to_be_visible()
    expect(page.locator(f'[data-adf-submission="{first_id}"]')).to_have_count(0)
    with page.expect_download() as pending:
        page.get_by_role('button', name='Export all to Excel').click()
    assert len(list(read_download(pending.value).values)) == 22
    print('PASS: recoverable trash excludes only selected records from the active list and standard export')
    browser.close()
