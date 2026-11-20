from pathlib import Path
import json, re, shutil, socket, subprocess, tempfile, time
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
        assert requests.get(api,timeout=5).status_code==503
        pw='test-only-local-password'
        hashed=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',pw],text=True)
        (storage/'auth.json').write_text(json.dumps({'username':'smoke','password_hash':hashed}))
        assert requests.get(api,timeout=5).status_code==401
        assert requests.get(api,params={'action':'health'},timeout=5).json()=={'status':'ok'}
        assert requests.get(api,auth=('smoke',pw),timeout=5).json()=={'groups':[]}
        assert requests.get(base+'storage/auth.json',timeout=5).status_code==404
    finally:
        server.terminate();server.wait(timeout=5)
