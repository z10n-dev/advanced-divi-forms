"""Non-destructive layout checks against a disposable WordPress fixture."""
import json
import os
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

fixture = json.loads(Path(sys.argv[1]).read_text())
base = 'http://127.0.0.1:8767'
url = f"{base}/wp-admin/admin.php?page=advanced-divi-forms&form_id={fixture['form']}"
with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=os.environ.get('ADF_CHROMIUM'))
    context = browser.new_context(viewport={'width': 1280, 'height': 900})
    context.add_cookies(fixture['cookies'])
    page = context.new_page()
    page.goto(url, wait_until='networkidle')
    page.locator('input[form="adf-bulk-form"]').first.check()
    page.locator('#adf-bulk-operation').select_option('delete')
    button = page.locator('#adf-bulk-form [name="apply"]')
    expect(button).to_have_class(__import__('re').compile(r'\badf-button-danger\b'))
    assert button.evaluate('e => getComputedStyle(e).backgroundColor') == 'rgb(179, 45, 46)'
    button.hover()
    assert button.evaluate('e => getComputedStyle(e).backgroundColor') == 'rgb(138, 36, 36)'
    button.focus()
    assert button.evaluate('e => getComputedStyle(e).boxShadow') != 'none'
    page.locator('#adf-bulk-operation').select_option('mark_read')
    assert 'adf-button-danger' not in button.get_attribute('class')
    bulk = page.locator('#adf-bulk-form').bounding_box()
    table = page.locator('.adf-table-scroll').bounding_box()
    assert table['y'] - (bulk['y'] + bulk['height']) >= 16
    assert page.locator('.widefat tbody td').first.evaluate('e => parseFloat(getComputedStyle(e).paddingTop)') >= 12
    first_id = page.locator('[data-adf-submission]').first.get_attribute('data-adf-submission')
    page.locator('[data-adf-submission]').first.locator('summary').click()
    edit = page.locator('[data-adf-submission]').first.locator('.adf-quick-edit')
    assert edit.evaluate('e => parseFloat(getComputedStyle(e).paddingTop)') >= 16
    for width in [390, 768]:
        page.set_viewport_size({'width': width, 'height': 900})
        assert page.locator('body').evaluate('e => e.scrollWidth <= innerWidth + 2')
        assert button.is_visible()
    page.set_viewport_size({'width': 1280, 'height': 900})
    page.goto(url + '&submission_id=' + first_id, wait_until='networkidle')
    delete = page.locator('[name="adf_delete"]')
    assert delete.evaluate('e => getComputedStyle(e).backgroundColor') == 'rgb(179, 45, 46)'
    assert page.locator('.adf-submission-actions').evaluate('e => parseFloat(getComputedStyle(e).marginTop)') >= 24
    print('PASS: red bulk/single delete buttons, hover/focus, action reset, spacing and narrow viewports')
    browser.close()
