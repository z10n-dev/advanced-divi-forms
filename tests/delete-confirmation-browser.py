"""Measure first-line alignment in both delete confirmations; no records deleted."""
import json
import os
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright

fixture = json.loads(Path(sys.argv[1]).read_text())
base = 'http://127.0.0.1:8767/wp-admin/admin.php?page=advanced-divi-forms'
measure = """label => {
    const input = label.querySelector('input');
    const walker = document.createTreeWalker(label, NodeFilter.SHOW_TEXT);
    let node;
    while ((node = walker.nextNode())) {
        if (node.textContent.trim()) break;
    }
    const range = document.createRange();
    range.selectNodeContents(node);
    const text = range.getClientRects()[0];
    const box = input.getBoundingClientRect();
    const style = getComputedStyle(label);
    return {offset: (box.top + box.height / 2) - (text.top + text.height / 2),
            gap: text.left - box.right, lines: range.getClientRects().length,
            display: style.display, hasTextElement: Boolean(label.querySelector('span')),
            labelTop: label.getBoundingClientRect().top, lineHeight: style.lineHeight, fontSize: style.fontSize,
            inputTop: box.top, inputHeight: box.height, textTop: text.top, textHeight: text.height,
            inputFont: getComputedStyle(input).fontSize, marginTop: getComputedStyle(input).marginTop};
}"""
with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=os.environ.get('ADF_CHROMIUM'))
    context = browser.new_context()
    context.add_cookies(fixture['cookies'])
    page = context.new_page()
    results = []
    for kind in ['bulk', 'single']:
        url = base + '&form_id=' + str(fixture['form'])
        if kind == 'single':
            url += '&submission_id=' + str(fixture['ids'][-1])
        page.goto(url, wait_until='networkidle')
        if kind == 'bulk':
            page.locator('#adf-bulk-operation').select_option('delete')
        for width in [1280, 768, 390]:
            page.set_viewport_size({'width': width, 'height': 900})
            label = page.locator('.adf-delete-confirm label')
            geometry = label.evaluate(measure)
            results.append({'kind': kind, 'width': width, **geometry})
            print(json.dumps(results[-1]))
    browser.close()
assert all(abs(r['offset']) <= 2.5 for r in results), 'Checkboxes must align with the first text line, including wrapped mobile text.'
assert all(r['gap'] >= 8 for r in results), 'Text must remain separated from the checkbox.'
assert all(r['hasTextElement'] for r in results), 'Confirmation text needs its own layout element, not an anonymous flex item.'
print('PASS: single and bulk confirmation checkbox/first-line alignment at desktop, tablet and mobile widths')
