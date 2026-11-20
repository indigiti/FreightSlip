from pathlib import Path
import base64, hashlib, json, os, re, shutil, socket, subprocess, tempfile, time
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
    server=subprocess.Popen(['php','-d','session.save_path=/no-such/php-session-directory',
                             '-S',f'127.0.0.1:{port}','-t',str(host/'public_html')])
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
        assert 'WWW-Authenticate' not in requests.get(api,timeout=5).headers
        assert requests.get(api,params={'action':'health'},timeout=5).json()=={'status':'ok'}
        client=requests.Session()
        def sign_in(username,password):
            r=client.post(api,params={'action':'login'},headers={'X-LR-Studio':'1'},
                          json={'username':username,'password':password},timeout=8)
            assert r.ok,r.text
            return r.json()['csrf']
        csrf=sign_in('smoke',pw)
        assert (storage/'sessions').is_dir()
        assert list((storage/'sessions').glob('sess_*')), 'PHP session was not stored in private storage'
        assert client.get(api,timeout=5).json()=={'groups':[]}
        assert client.get(api,params={'action':'session'},timeout=5).json()['authenticated'] is True
        # Browser-generated offline PBKDF2 credentials: same input format as Web Crypto.
        salt = bytes(range(16))
        salted = hashlib.pbkdf2_hmac('sha256',pw.encode('utf-8'),salt,600000,dklen=32)
        wizard_auth = {'username':'operator','pbkdf2_sha256':{
            'iterations':600000,
            'salt':base64.b64encode(salt).decode('ascii'),
            'hash':base64.b64encode(salted).decode('ascii'),
        }}
        (storage/'auth.json').write_text(json.dumps(wizard_auth))
        # Changing credentials invalidates the old session without a browser challenge.
        assert client.get(api,timeout=5).status_code==401
        assert client.post(api,params={'action':'login'},headers={'X-LR-Studio':'1'},
                           json={'username':'operator','password':'wrong-password'},timeout=8).status_code==401
        csrf=sign_in('operator',pw)
        assert client.get(api,timeout=5).json()=={'groups':[]}
        assert requests.get(api,params={'action':'health'},timeout=5).json()=={'status':'ok'}
        malformed = {'username':'operator','pbkdf2_sha256':dict(wizard_auth['pbkdf2_sha256'],salt='bad')}
        (storage/'auth.json').write_text(json.dumps(malformed))
        assert requests.get(api,params={'action':'health'},timeout=5).status_code==503
        (storage/'auth.json').write_text(json.dumps(wizard_auth))
        assert requests.get(base+'setup/',timeout=5).ok
        assert requests.get(base+'login/',timeout=5).ok
        sample = Path('public/example-dispatch.csv')
        with sample.open('rb') as handle:
            upload = client.post(api, params={'action':'upload'},
                                   headers={'X-LR-Studio':'1','X-LR-CSRF':csrf}, files={'workbook':(sample.name,handle)}, timeout=5)
        assert upload.ok, upload.text
        assert client.post(api,params={'action':'logout'},headers={'X-LR-Studio':'1','X-LR-CSRF':csrf},json={},timeout=5).ok
        assert client.get(api,params={'action':'session'},timeout=5).status_code==401
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
