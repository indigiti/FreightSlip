from pathlib import Path
import json, os, re, shutil, socket, subprocess, tempfile, time
import requests

release = Path('release')
meta = json.loads((release/'RELEASE.json').read_text())
assert meta['schema']=='DIGIOPS-RELEASE/1' and meta['persistentPaths']==['storage/']
assert meta['publicPath']=='public_html/freightslip/' and meta['privatePath']=='private_html/freightslip/'
with tempfile.TemporaryDirectory() as d:
    host = Path(d)
    public = host/'public_html/freightslip'
    private = host/'private_html/freightslip'
    shutil.copytree(release/'public', public)
    shutil.copytree(release/'private', private)
    assert not (public/'vendor').exists() and not (public/'storage').exists()
    app = private/'app'
    runtime_id = (app/'digiops-runtime.sha256').read_text().strip()
    assert re.fullmatch(r'[a-f0-9]{64}', runtime_id)
    bundled = app/'runtimes'/runtime_id
    mode = os.environ['FREIGHTSLIP_PACKAGE_MODE']
    assert not (app/'vendor').exists()
    assert (bundled/'autoload.php').is_file() == (mode == 'full')
    storage = private/'storage'; storage.mkdir()
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0)); port=s.getsockname()[1]
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(host/'public_html')])
    base=f'http://127.0.0.1:{port}/freightslip/'
    api=base+'api/index.php'
    try:
        for _ in range(40):
            try: page=requests.get(base,timeout=1); break
            except requests.ConnectionError: time.sleep(.1)
        else: raise RuntimeError('PHP did not start')
        assert page.ok
        scripts=re.findall(r'<script[^>]* src="(/freightslip/_next/[^"?]+)', page.text)
        assert scripts and all(requests.get(f'http://127.0.0.1:{port}'+src,timeout=5).ok for src in scripts)
        denied = requests.get(api,timeout=5)
        assert denied.status_code==503
        if mode == 'update':
            assert 'Matching FreightSlip runtime unavailable' in denied.json()['error']
            # Simulate DigiOps overlay onto a host with the original full release installed.
            shutil.copytree('php/vendor', app/'vendor')
            assert requests.get(api,timeout=5).json()['error'].startswith('Configure private storage')
        pw='test-only-local-password'
        hashed=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',pw],text=True)
        (storage/'auth.json').write_text(json.dumps({'username':'smoke','password_hash':hashed}))
        assert requests.get(api,timeout=5).status_code==401
        assert requests.get(api,params={'action':'health'},timeout=5).json()=={'status':'ok'}
        assert requests.get(api,auth=('smoke',pw),timeout=5).json()=={'groups':[]}
        sample = Path('public/example-dispatch.csv')
        with sample.open('rb') as handle:
            upload = requests.post(api, params={'action':'upload'}, auth=('smoke',pw),
                                   headers={'X-LR-Studio':'1'}, files={'workbook':(sample.name,handle)}, timeout=5)
        assert upload.ok, upload.text
        if mode == 'update':
            # A versioned install also survives the overlay and works without the legacy vendor path.
            bundled.parent.mkdir()
            (app/'vendor').rename(bundled)
            assert requests.get(api,params={'action':'health'},timeout=5).json()=={'status':'ok'}
            installed = bundled/'composer/installed.json'
            manifest = json.loads(installed.read_text())
            manifest['packages'][0]['version'] = 'wrong-version'
            installed.write_text(json.dumps(manifest))
            assert requests.get(api,params={'action':'health'},timeout=5).status_code==503
        assert requests.get(base+'storage/auth.json',timeout=5).status_code==404
    finally:
        server.terminate();server.wait(timeout=5)
