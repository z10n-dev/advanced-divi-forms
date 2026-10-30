"""Chromium/openpyxl acceptance against synthetic disposable filter fixtures."""
import json
import os
import sys
from pathlib import Path
from urllib.parse import urlencode, urlparse, parse_qs
import openpyxl
from playwright.sync_api import sync_playwright, expect

path = Path(sys.argv[1])
fixture = json.loads(path.read_text())
base = 'http://127.0.0.1:8767'
filters = {'adf_search': 'Needle', 'adf_status': 'unread', 'adf_from': '2026-10-02', 'adf_to': '2026-10-02', 'adf_order': 'oldest'}
url = base + '/wp-admin/admin.php?' + urlencode({'page': 'advanced-divi-forms', 'form_id': fixture['form'], **filters})
with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=os.environ.get('ADF_CHROMIUM'))
    context = browser.new_context(accept_downloads=True, viewport={'width': 1280, 'height': 1000})
    context.add_cookies(fixture['cookies'])
    page = context.new_page()
    page.goto(url, wait_until='domcontentloaded')
    rows = page.locator('[data-adf-submission]')
    expect(rows).to_have_count(20)
    nonce = page.locator('.adf-export-all input[name="_wpnonce"]').input_value()
    post = {'action': 'adf_export_submissions', 'form_id': str(fixture['form']), '_wpnonce': nonce}
    for extra in [
        {'scope': 'selected'},
        {'scope': 'bogus'},
        {'scope': 'all', 'submission_ids[]': str(fixture['ids'][0])},
        {'scope': 'filtered', 'submission_ids[]': str(fixture['ids'][0])},
        {'scope': 'filtered', 'adf_from': '2026-02-31'},
        {'scope': 'selected', 'submission_ids[]': str(fixture['foreign_id'])},
    ]:
        response = context.request.post(base + '/wp-admin/admin-post.php', form={**post, **extra})
        assert response.status == 400, f'Invalid/ambiguous export scope must fail closed: {extra}; HTTP {response.status}'
    print('PASS: HTTP export scopes reject empty/ambiguous/foreign/invalid inputs')
    def download_ids(click, name):
        with page.expect_download() as waiting:
            click()
        target = path.parent / name
        waiting.value.save_as(target)
        book = openpyxl.load_workbook(target)
        values = list(book.active.values)
        assert all(cell.data_type != 'f' for row in book.active for cell in row)
        assert any('00123' in row and '=1+1' in row for row in values[1:])
        book.close()
        return {int(row[0]) for row in values[1:]}
    filtered = download_ids(lambda: page.get_by_role('button', name='Export filtered to Excel', exact=True).click(), 'filtered-export.xlsx')
    assert filtered == set(fixture['matching_ids']), 'Filtered XLSX must contain exactly the filtered matches across all pages'
    all_ids = download_ids(lambda: page.get_by_role('button', name='Export all to Excel', exact=True).click(), 'unfiltered-export.xlsx')
    assert all_ids == set(fixture['ids']), 'Export all must include every answer of only this form'
    boxes = page.locator('input[form="adf-bulk-form"][name="submission_ids[]"]')
    selected = {int(boxes.nth(i).input_value()) for i in range(2)}
    boxes.nth(0).check(); boxes.nth(1).check()
    page.locator('#adf-bulk-operation').select_option('export')
    actual = download_ids(lambda: page.get_by_role('button', name='Apply', exact=True).click(), 'filter-selected-export.xlsx')
    assert actual == selected
    page.locator('#adf-select-all').uncheck()
    print(f'PASS: openpyxl verified {len(filtered)} filtered / {len(all_ids)} all / {len(actual)} selected, literal cells and no foreign answers')
    for width in [1280, 768, 390]:
        page.set_viewport_size({'width': width, 'height': 1000})
        assert page.locator('body').evaluate('e => e.scrollWidth <= innerWidth + 2'), f'Filter screen overflows at {width}px'
        gaps = page.locator('.adf-filter-field').evaluate_all("""labels => labels.map(label => {
            const text = [...label.childNodes].find(n => n.nodeType === Node.TEXT_NODE && n.textContent.trim());
            const start = text.textContent.indexOf(text.textContent.trim());
            const range = document.createRange(); range.setStart(text, start); range.setEnd(text, start + text.textContent.trim().length);
            return label.querySelector('input,select').getBoundingClientRect().top - range.getBoundingClientRect().bottom;
        })""")
        assert len(gaps) == 5 and min(gaps) >= 4, f'Filter labels must sit clearly above their controls at {width}px: {gaps}'

        expect(page.get_by_role('button', name='Filter submissions', exact=True)).to_be_visible()
        page.screenshot(path=str(path.parent / f'filters-{width}.png'), full_page=True)
    page.set_viewport_size({'width': 1280, 'height': 1000})
    page.get_by_role('link', name='2', exact=True).click()
    expect(rows).to_have_count(len(fixture['matching_ids']) - 20)
    def assert_context():
        query = parse_qs(urlparse(page.url).query)
        assert all(query.get(key) == [value] for key, value in filters.items()), page.url
        assert query.get('adf_paged') == ['2'], page.url
    assert_context()
    row = rows.first
    row.locator('summary').click()
    row.locator('textarea[name="note"]').fill('Filtered context preserved')
    row.get_by_role('button', name='Save changes', exact=True).click()
    assert_context()
    expect(rows.first.locator('.adf-note-preview')).to_have_text('Filtered context preserved')
    rows.first.locator('td a').first.click()
    expect(page.locator('.adf-submission-detail')).to_be_visible()
    page.get_by_role('link', name='Back to submissions', exact=False).click()
    assert_context()
    expect(rows).to_have_count(5)
    print('PASS: filter context survives page 2, Quick Edit and detail/back navigation')
    page.locator('#adf-select-all').check()
    page.locator('#adf-bulk-operation').select_option('mark_read')
    page.get_by_role('button', name='Apply', exact=True).click()
    expect(rows).to_have_count(20)
    assert page.locator('#adf-bulk-form input[name="return_page"]').input_value() == '1'
    expect(page.locator('.adf-result-count')).to_have_text('20 matching submissions')
    print('PASS: status-changing actions preserve filters and clamp an emptied last page')
    browser.close()
