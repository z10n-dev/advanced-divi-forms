"""Real-browser single-answer Trash/restore tracer; disposable fixtures only."""
import json
import os
import sys
from io import BytesIO
import openpyxl
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

fixture = json.loads(Path(sys.argv[1]).read_text())
base = 'http://127.0.0.1:8767'
url = f"{base}/wp-admin/admin.php?page=advanced-divi-forms&form_id={fixture['form']}"
with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=os.environ.get('ADF_CHROMIUM'))
    context = browser.new_context(viewport={'width': 1280, 'height': 1000})
    context.add_cookies(fixture['cookies'])
    page = context.new_page()
    page.goto(url, wait_until='domcontentloaded')
    rows = page.locator('[data-adf-submission]')
    expect(rows).to_have_count(20)
    target = int(rows.first.get_attribute('data-adf-submission'))
    received = rows.first.locator('td a').first.inner_text()
    note = "Retain O'Brien C:\\folder"
    rows.first.locator('summary').click()
    rows.first.locator('textarea[name="note"]').fill(note)
    rows.first.locator('select[name="read"]').select_option('1')
    rows.first.get_by_role('button', name='Save changes', exact=True).click()
    rows.first.locator('td a').first.click()
    page.get_by_role('button', name='Move to Trash', exact=True).click()
    page.get_by_role('link', name='Trash (1)', exact=True).click()
    expect(rows).to_have_count(1)
    assert int(rows.first.get_attribute('data-adf-submission')) == target
    rows.first.locator('td a').first.click()
    expect(page.locator('.adf-submission-detail')).to_be_visible()
    page.get_by_role('button', name='Restore submission', exact=True).click()
    page.get_by_role('link', name=f"Active ({len(fixture['ids'])})", exact=True).click()
    restored = page.locator(f'[data-adf-submission="{target}"]')
    expect(restored).to_be_visible()
    expect(restored.locator('td a').first).to_have_text(received)
    expect(restored.locator('.adf-status')).to_have_text('Read')
    expect(restored.locator('.adf-note-preview')).to_have_text(note)
    print('PASS: real single-answer Trash/restore retains ID/date/status/note and exact counters')
    chosen = {int(rows.nth(i).get_attribute('data-adf-submission')) for i in range(2)}
    def select_ids(ids):
        for item in ids:
            page.locator(f'[data-adf-submission="{item}"] input[type="checkbox"]').check()
    def download_ids(click):
        with page.expect_download() as pending:
            click()
        book = openpyxl.load_workbook(BytesIO(Path(pending.value.path()).read_bytes()))
        sheet = book.active
        assert sheet is not None
        result = {int(row[0]) for row in list(sheet.values)[1:]}
        assert all(cell.data_type != 'f' for row in sheet for cell in row)
        book.close()
        return result
    def trash_chosen():
        select_ids(chosen)
        page.locator('#adf-bulk-operation').select_option('trash')
        assert 'adf-button-danger' in page.locator('#adf-bulk-form [name="apply"]').get_attribute('class'), 'Move to Trash must remain a red destructive action'
        expect(page.locator('#adf-bulk-form .adf-delete-confirm')).to_have_count(0)
        page.get_by_role('button', name='Apply', exact=True).click()
        page.get_by_role('link', name='Trash (2)', exact=True).click()
        expect(rows).to_have_count(2)
    trash_chosen()
    actual = download_ids(lambda: page.get_by_role('button', name='Export filtered Trash to Excel', exact=True).click())
    assert actual == chosen
    select_ids(chosen)
    page.locator('#adf-bulk-operation').select_option('restore')
    page.get_by_role('button', name='Apply', exact=True).click()
    page.get_by_role('link', name=f"Active ({len(fixture['ids'])})", exact=True).click()
    expect(page.locator(f'[data-adf-submission="{target}"] .adf-note-preview')).to_have_text(note)
    trash_chosen()
    # A nonce-protected selection is still insufficient without explicit permanent-delete confirmation.
    bulk_nonce = page.locator('#adf-bulk-form input[name="_wpnonce"]').input_value()
    remaining, single = sorted(chosen)
    invalid = context.request.post(base + '/wp-admin/admin-post.php', form={'action': 'adf_manage_submissions', 'form_id': str(fixture['form']), '_wpnonce': bulk_nonce, 'operation': 'delete', 'adf_view': 'trash', 'submission_ids[]': str(remaining)})
    assert invalid.status == 400
    page.locator(f'[data-adf-submission="{single}"] td a').first.click()
    form = page.locator('.adf-submission-actions')
    nonce = form.locator('input[name="_wpnonce"]').input_value()
    post = {'action': 'adf_update_submission', 'submission_id': str(single), '_wpnonce': nonce, 'adf_delete': '1', 'adf_view': 'trash'}
    assert context.request.post(base + '/wp-admin/admin-post.php', form=post).status == 400
    assert context.request.post(base + '/wp-admin/admin-post.php', form={**post, '_wpnonce': 'invalid', 'adf_confirm_delete': '1'}).status == 403
    assert context.request.get(base + '/wp-admin/admin-post.php?action=adf_update_submission').status == 405
    for width in [1280, 768, 390]:
        page.set_viewport_size({'width': width, 'height': 1000})
        assert page.locator('body').evaluate('e => e.scrollWidth <= innerWidth + 2')
        expect(page.get_by_role('button', name='Delete permanently', exact=True)).to_be_visible()
        restore_box = page.get_by_role('button', name='Restore submission', exact=True).bounding_box()
        confirm_box = page.locator('.adf-delete-confirm').bounding_box()
        assert confirm_box['y'] - restore_box['y'] - restore_box['height'] >= 16, 'Reversible restore must be visually separated from permanent-delete confirmation'
        page.screenshot(path=str(Path(sys.argv[1]).parent / f'trash-detail-{width}.png'), full_page=True)
    page.locator('[name="adf_confirm_delete"]').check()
    page.get_by_role('button', name='Delete permanently', exact=True).click()
    expect(rows).to_have_count(1)
    assert context.request.get(url + '&adf_view=trash&submission_id=' + str(single)).status == 404
    select_ids({remaining})
    page.locator('#adf-bulk-operation').select_option('delete')
    expect(page.locator('#adf-bulk-form .adf-delete-confirm')).to_be_visible()
    page.locator('[name="confirm_delete"]').check()
    page.get_by_role('button', name='Apply', exact=True).click()
    expect(rows).to_have_count(0)
    page.get_by_role('link', name=f"Active ({len(fixture['ids'])-2})", exact=True).click()
    active = download_ids(lambda: page.get_by_role('button', name='Export all to Excel', exact=True).click())
    assert active == set(fixture['ids']) - chosen
    print('PASS: bulk trash/restore, explicit Trash XLSX, guarded single/bulk permanent deletion, default export exclusion and three viewports')
    browser.close()
