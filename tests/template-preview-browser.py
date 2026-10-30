"""Real CodeMirror, protected AJAX and opaque-origin offline preview checks."""
import json
import os
import subprocess
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

path = Path(sys.argv[1])
f = json.loads(path.read_text())
base = 'http://127.0.0.1:8767'
html = '<p id="preview-value">{{fields.message}}</p><p>{{fields.missing}} {{unknown.token}}</p><a id="preview-link" href="https://preview.invalid/{{fields.name}}">Disabled link</a><img src="https://preview.invalid/pixel"><p style="background-image:url(https://preview.invalid/bg)">Background</p><script>parent.__adfPreviewExecuted=1;fetch("https://preview.invalid/script");</script>{{form.summary}}'
values = json.dumps({'name': 'Ada & Bob', 'message': 'Literal {{fields.name}}'})
with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, executable_path=os.environ.get('ADF_CHROMIUM'))
    context = browser.new_context(viewport={'width': 1280, 'height': 1000})
    context.add_cookies(f['cookies'])
    external = []
    context.route('https://preview.invalid/**', lambda route: (external.append(route.request.url), route.abort()))
    page = context.new_page()
    page.goto(f"{base}/wp-admin/post.php?post={f['template']}&action=edit", wait_until='networkidle')
    expect(page.locator('#adf-preview-run')).to_be_visible()
    page.wait_for_function('Boolean(document.querySelector(".CodeMirror") && document.querySelector(".CodeMirror").CodeMirror)')
    def set_html(text, cursor=None):
        page.evaluate('arg => {const e=document.querySelector(".CodeMirror").CodeMirror; e.setValue(arg.text); if(arg.cursor !== null)e.setCursor(0,arg.cursor);}', {'text': text, 'cursor': cursor})
    set_html('<p></p>', 3)
    page.get_by_role('button', name='{{fields.name}}', exact=True).click()
    assert page.evaluate('document.querySelector(".CodeMirror").CodeMirror.getValue()') == '<p>{{fields.name}}</p>'
    page.locator('#adf-template-subject').fill('Subject: ')
    page.locator('#adf-insert-target').select_option('subject')
    page.get_by_role('button', name='{{fields.name}}', exact=True).click()
    expect(page.locator('#adf-template-subject')).to_have_value('Subject: {{fields.name}}')
    before = page.locator('#adf-template-subject').input_value()
    page.get_by_role('button', name='{{form.summary}}', exact=True).click()
    assert page.locator('#adf-template-subject').input_value() == before
    print('PASS: placeholders insert into real CodeMirror/subject; summary remains HTML-body-only')
    set_html(html)
    page.locator('#adf-template-subject').fill('Hi {{fields.name}}')
    page.locator('#adf-preview-fields').fill(values)
    with page.expect_response(lambda r: 'admin-ajax.php' in r.url and r.request.method == 'POST') as waiting:
        page.locator('#adf-preview-run').click()
    assert waiting.value.status == 200
    expect(page.locator('#adf-preview-status')).to_contain_text('Nothing was saved')
    expect(page.locator('#adf-preview-subject')).to_have_text('Hi Ada & Bob')
    preview = page.frame_locator('#adf-template-preview')
    expect(preview.locator('#preview-value')).to_have_text('Literal {{fields.name}}')
    expect(preview.locator('table')).to_have_count(1)
    expect(preview.locator('script')).to_have_count(0)
    assert page.locator('#adf-template-preview').get_attribute('sandbox') == ''
    assert preview.locator('#preview-link').get_attribute('href') is None
    preview.locator('#preview-link').click()
    assert page.evaluate('window.__adfPreviewExecuted || null') is None
    assert page.evaluate('(() => {try {return Boolean(document.querySelector("#adf-template-preview").contentWindow.document);} catch(e){return false;}})()') is False
    assert not external, f'Preview attempted an external resource/navigation request: {external}'
    expect(page.locator('#adf-preview-diagnostics')).to_contain_text('Missing test-data fields: {{fields.missing}}')
    expect(page.locator('#adf-preview-diagnostics')).to_contain_text('Unsupported placeholders: {{unknown.token}}')
    expect(page.locator('#adf-preview-diagnostics')).to_contain_text('Not expanded in HTML attributes: {{fields.name}}')
    page.locator('#adf-preview-fields').fill('{broken')
    page.locator('#adf-preview-run').click()
    expect(page.locator('#adf-preview-status')).to_have_class('adf-preview-error')
    expect(page.locator('#adf-preview-status')).to_contain_text('JSON object')
    page.locator('#adf-preview-fields').fill(values)
    page.locator('#adf-preview-run').click()
    expect(page.locator('#adf-preview-subject')).to_have_text('Hi Ada & Bob')
    button = page.locator('#adf-preview-run')
    payload = {'action': 'adf_preview_template', 'template_id': str(f['template']), 'nonce': button.get_attribute('data-preview-nonce'), 'subject': 'Subject', 'html': '<p>Body</p>', 'fields_json': '{}'}
    assert context.request.post(base + '/wp-admin/admin-ajax.php', form={**payload, 'nonce': 'invalid'}).status == 403
    assert context.request.get(base + '/wp-admin/admin-ajax.php?action=adf_preview_template').status == 405
    for width in [1280, 768, 390]:
        page.set_viewport_size({'width': width, 'height': 1000})
        assert page.locator('body').evaluate('e => e.scrollWidth <= innerWidth + 2'), f'Editor overflows at {width}px'
        expect(preview.locator('#preview-value')).to_have_text('Literal {{fields.name}}')
        expect(preview.locator('table')).to_be_visible()
        page.locator('.adf-template-preview').screenshot(path=str(path.parent / f'template-preview-{width}.png'))
        # Cross-origin iframe layers may be omitted by a beyond-viewport element
        # screenshot. Also capture the actual viewport after scrolling the frame
        # onscreen and allowing its compositor a paint; DOM assertions stay above.
        page.locator('#adf-template-preview').scroll_into_view_if_needed()
        page.wait_for_timeout(150)
        page.screenshot(path=str(path.parent / f'template-preview-visible-{width}.png'))
    assert not external
    browser.close()
php = f'$id={int(f["template"])};echo wp_json_encode(array("subject"=>get_post_meta($id,"_adf_subject",true),"html"=>get_post_meta($id,"_adf_html",true),"submissions"=>array_sum((array)wp_count_posts("adf_submission"))));'
snapshot = json.loads(subprocess.check_output(['/home/hermes/tools/php-adf/wp', '--path=/home/hermes/.hermes/cache/scratch/adf-test/wp', 'eval', php]))
assert snapshot == {'subject': f['saved_subject'], 'html': f['saved_html'], 'submissions': f['submissions']}
print('PASS: unsaved synthetic preview, missing/unknown/attribute diagnostics, opaque sandbox, no external requests/script execution, unchanged stored content/submissions and three viewports')
