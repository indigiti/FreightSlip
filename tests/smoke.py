from pathlib import Path
import tempfile, subprocess, time, os, socket, re
import requests, json, hashlib, base64

root = Path(__file__).resolve().parents[1]
with socket.socket() as s:
    s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
base=f'http://127.0.0.1:{port}/index.php'
with tempfile.TemporaryDirectory() as db:
    username='operator'
    password='test-only-password-123456'
    salt=bytes(range(16))
    hashed=hashlib.pbkdf2_hmac('sha256',password.encode(),salt,600000)
    Path(db,'auth.json').write_text(json.dumps({'username':username,'pbkdf2_sha256':{
        'iterations':600000,'salt':base64.b64encode(salt).decode(),'hash':base64.b64encode(hashed).decode(),
    }}))
    client=requests.Session()
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root/'php/web')],env={**os.environ,'LR_DATA_DIR':db},stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        for _ in range(40):
            try:
                assert client.get(base,timeout=1).status_code==401;break
            except requests.ConnectionError: time.sleep(.05)
        unauthorized=client.get(base,timeout=2)
        assert unauthorized.status_code==401 and 'WWW-Authenticate' not in unauthorized.headers
        rejected=client.post(base,params={'action':'login'},headers={'X-LR-Studio':'1'},json={
            'username':username,'password':'wrong'
        },timeout=5)
        assert rejected.status_code==401
        logged=client.post(base,params={'action':'login'},headers={'X-LR-Studio':'1'},json={
            'username':username,'password':password
        },timeout=5)
        assert logged.status_code==200,logged.text
        csrf=logged.json()['csrf']
        assert client.get(base,params={'action':'session'},timeout=2).json()['username']==username
        hdr={'X-LR-Studio':'1','X-LR-CSRF':csrf}
        # Office defaults come from authenticated private settings, not from Excel.
        initial=client.get(base,params={'action':'settings'},timeout=2).json()
        assert initial['version']==0
        assert initial['settings']['carrier']=='SHRIRAM TRANSPORT'
        assert initial['settings']['consignor_address']=="C/O Shriram Enterprises\nUrulidevachi, Pune 412308, Maharashtra"
        assert initial['settings']['address'].startswith('MILKAT 2345')
        assert client.post(base,params={'action':'settings'},headers={'X-LR-Studio':'1'},
                           json={'version':0,'settings':initial['settings']},timeout=2).status_code==403
        assert client.post(base,params={'action':'settings'},headers=hdr,
                           json={'version':0,'settings':dict(initial['settings'],carrier='')},timeout=2).status_code==422
        saved=client.post(base,params={'action':'settings'},headers=hdr,
                          json={'version':0,'settings':dict(initial['settings'],address='Test office road, Pune, Maharashtra')},timeout=2)
        assert saved.ok, saved.text
        assert saved.json()['version']==1
        assert client.post(base,params={'action':'settings'},headers=hdr,
                           json={'version':0,'settings':initial['settings']},timeout=2).status_code==409
        assert client.post(base,params={'action':'review'},headers={'X-LR-Studio':'1'},json={},timeout=2).status_code==403
        def post(action,**kwargs): return client.post(base,params={'action':action},headers=hdr,timeout=5,**kwargs)
        with (root/'public/example-dispatch.csv').open('rb') as f:
            u=post('upload',files={'workbook':f}).json()
        assert u['rows']==3 and u['map']['freight']==7 and u['map']['address']==11
        assert 'carrier' not in u['required'] # configured by office settings, not a mandatory Excel column
        if os.environ.get('LR_RELEASE_SMOKE'):
            import csv, io
            from openpyxl import Workbook
            book=Workbook(); sheet=book.active; sheet.title='Dispatch'
            sheet.append(['Dispatch report']); sheet.append([None])
            for row in csv.reader((root/'public/example-dispatch.csv').read_text().splitlines()): sheet.append(row)
            xlsx=io.BytesIO();book.save(xlsx)
            x=post('upload',files={'workbook':('dispatch.xlsx',xlsx.getvalue())})
            assert x.ok, x.text
            xm=x.json();assert xm['sheet']=='Dispatch' and xm['header']==3 and xm['map']['freight']==7 and xm['rows']==3
        source=(root/'public/example-dispatch.csv').read_bytes()
        shifted=b'Dispatch report\n\n'+source.replace(b'TRANSPORT_ADDRESS',b'',1)
        detected=post('upload',files={'workbook':('shifted.csv',shifted)}).json()
        assert detected['header']==3 and detected['map']['address']==11 and detected['inferred']==['address']
        wrong=shifted.replace(b'MILKAT 2345',b'Unknown address',1)
        unknown=post('upload',files={'workbook':('unknown.csv',wrong)}).json()
        assert 'address' not in unknown['map']
        ambiguous=source.replace(b'FRIGHT,TYPE',b'FRIGHT,FREIGHT',1)
        amb=post('upload',files={'workbook':('ambiguous.csv',ambiguous)}).json()
        assert 'freight' not in amb['map']
        rules={'date':'so_date','freight':'base','allow_zero':False}
        data={'upload':u['upload'],'map':u['map'],'rules':rules}
        assert post('review',json={**data,'rules':{**rules,'date':''}}).status_code==422
        r=post('review',json=data).json();g=r['groups'];assert [x['status'] for x in g]==['ready','ready','blocked']
        assert g[0]['items'][0]['obd']=='00010001' and g[0]['items'][0]['weight']==''
        assert all(x['common']['address']=='Test office road, Pune, Maharashtra' for x in g)
        assert all(x['common']['consignor_address'].startswith('C/O Shriram Enterprises') for x in g)
        assert post('issue',json={'review':r['review'],'keys':[g[0]['key'],g[2]['key']]}).status_code==422
        assert client.get(base,timeout=2).json()['groups']==[]
        p=client.get(base,params={'action':'preview','keys':g[0]['key'],'review':r['review']},timeout=2)
        assert p.ok and p.text.count('DRAFT · NOT ISSUED')==1 and p.text.count('370.00')==1 and '48 L' in p.text
        assert p.text.count('<div class="sheet')==1 and 'A4 portrait' in p.text
        if os.environ.get('LR_SAMPLE_HTML'): Path(os.environ['LR_SAMPLE_HTML']).write_text(p.text)
        j={'review':r['review'],'keys':[g[0]['key'],g[1]['key']]}
        assert post('issue',json=j).status_code==201
        assert post('issue',json=j).status_code==409
        assert post('review',json=data).json()['groups'][0]['status']=='issued'
        assert post('review',json={**data,'rules':{**rules,'allow_zero':True}}).json()['groups'][2]['status']=='ready'
        issued=client.get(base,params={'action':'preview','keys':g[0]['key']},timeout=2)
        assert issued.ok and 'DRAFT · NOT ISSUED' not in issued.text and '370.00' in issued.text
        assert len(client.get(base,timeout=2).json()['groups'])==2
        # Editing office settings must not rewrite already-issued LR snapshots.
        revised=client.post(base,params={'action':'settings'},headers=hdr,json={
            'version':1,'settings':dict(initial['settings'],carrier='ANOTHER TRANSPORTER')
        },timeout=2)
        assert revised.ok and revised.json()['version']==2
        unchanged=client.get(base,params={'action':'preview','keys':g[0]['key']},timeout=2)
        assert unchanged.ok and unchanged.text==issued.text
        layout=client.get(base,params={'action':'preview','keys':','.join(j['keys'])},timeout=2)
        assert layout.ok and layout.text.count('<div class="sheet')==1 and layout.text.count('<div class="half')==2
        assert all(layout.text.count(x['id'])==1 for x in g[:2])
        assert issued.text.count('<div class="half')==1
        assert client.get(base,params={'action':'preview','keys':','.join([g[0]['key']]*2)},timeout=2).status_code==422
        qrs=re.findall(r'<img class="lr-qr"[^>]*base64,([^"\s]+)',layout.text)
        assert len(qrs)==2 and qrs[0]!=qrs[1]
        clean_params={'keys':','.join(j['keys']),'layout':'clean'}
        clean=client.get(base,params={'action':'preview',**clean_params},timeout=5)
        assert clean.ok and 'data-layout="clean"' in clean.text and clean.text.count('Freight (Rs.)')==2
        assert clean.text.count('<div class="sheet')==1 and clean.text.count('<div class="half clean')==2
        assert all(clean.text.count(x['id'])==1 for x in g[:2])
        assert re.findall(r'<img class="lr-qr"[^>]*base64,([^"\s]+)',clean.text)==qrs
        single_clean=client.get(base,params={'action':'preview',**clean_params,'keys':g[0]['key']},timeout=5)
        assert single_clean.ok and single_clean.text.count('<div class="half clean')==1
        assert client.get(base,params={'action':'preview',**clean_params,'layout':'unknown'},timeout=5).status_code==422
        if os.environ.get('LR_CLEAN_HTML'): Path(os.environ['LR_CLEAN_HTML']).write_text(clean.text)
        details=client.get(base,params={'action':'details','key':g[0]['key']},timeout=2)
        assert details.ok and 'text/plain' in details.headers['Content-Type']
        assert 'OBD: 00010001' in details.text and 'Total freight (INR): 370.00' in details.text
        assert client.get(base,params={'action':'details','key':g[2]['key']},timeout=2).status_code==404
        if os.environ.get('LR_BATCH_HTML'): Path(os.environ['LR_BATCH_HTML']).write_text(layout.text)
        assert client.post(base,params={'action':'issue'},json=j,timeout=2).status_code==403
        assert client.get(base,params={'action':'pdf','keys':g[0]['key'],'review':r['review']},timeout=2).status_code==422
        if os.environ.get('LR_RELEASE_SMOKE'):
            from io import BytesIO
            from pypdf import PdfReader
            pdf=client.get(base,params={'action':'pdf','keys':','.join(j['keys'])},timeout=30)
            assert pdf.ok and pdf.content.startswith(b'%PDF'), pdf.text[:500]
            pages=PdfReader(BytesIO(pdf.content)).pages
            assert len(pages)==1
            page=pages[0]
            assert abs(float(page.mediabox.width)-595.28)<1 and abs(float(page.mediabox.height)-841.89)<1
            assert all(gp['id'] in page.extract_text() for gp in g[:2])
            clean_pdf=client.get(base,params={'action':'pdf',**clean_params},timeout=30)
            assert clean_pdf.ok and clean_pdf.content.startswith(b'%PDF'), clean_pdf.text[:500]
            clean_pages=PdfReader(BytesIO(clean_pdf.content)).pages
            assert len(clean_pages)==1
            clean_text=clean_pages[0].extract_text()
            assert 'Freight (Rs.)' in clean_text and all(clean_text.count(gp['id'])==1 for gp in g[:2])
        # Workspaces track exact issued imports; the recycle bin is a reversible,
        # authenticated state transition and never exposes its LRs to print routes.
        original=client.get(base,timeout=2).json()
        assert len(original['workspaces'])==1 and original['recycle']==[]
        original_ws=original['workspaces'][0]['id']
        assert original_ws==u['upload'] and len(original_ws)==32
        assert all(x['workspace_id']==original_ws for x in original['groups'])
        # A second upload with the SAME filename gets a completely independent workspace.
        second=source.replace(b'DEMO-26',b'DEMO-36')
        u2=post('upload',files={'workbook':('example-dispatch.csv',second)}).json()
        assert u2['upload']!=u['upload']
        r2=post('review',json={'upload':u2['upload'],'map':u2['map'],'rules':rules}).json()
        assert [x['status'] for x in r2['groups']]==['ready','ready','blocked']
        k2=[x['key'] for x in r2['groups'][:2]]
        assert post('issue',json={'review':r2['review'],'keys':k2}).status_code==201
        after_second=client.get(base,timeout=2).json()
        assert len(after_second['groups'])==4 and len(after_second['workspaces'])==2
        assert {x['id'] for x in after_second['workspaces']}=={original_ws,u2['upload']}
        # Moving a workspace requires CSRF, hides its documents, and retains data for restore.
        assert client.post(base,params={'action':'workspace_trash'},json={'id':u2['upload']},headers={'X-LR-Studio':'1'},timeout=3).status_code==403
        moved=post('workspace_trash',json={'id':u2['upload']})
        assert moved.ok and moved.json()['deleted']['count']==2
        day_30=moved.json()['deleted']['expires_at']
        assert day_30>moved.json()['deleted']['deleted_at']
        reduced=client.get(base,timeout=2).json()
        assert len(reduced['groups'])==2 and len(reduced['workspaces'])==1
        assert len(reduced['recycle'])==1 and reduced['recycle'][0]['count']==2
        assert client.get(base,params={'action':'preview','keys':k2[0]},timeout=3).status_code==404
        assert client.get(base,params={'action':'details','key':k2[0]},timeout=3).status_code==404
        assert client.get(base,params={'action':'pdf','keys':k2[0]},timeout=3).status_code==404
        assert post('issue',json={'review':r2['review'],'keys':k2}).status_code==409
        assert post('workspace_trash',json={'id':u2['upload']}).status_code==409
        restored=post('workspace_restore',json={'id':u2['upload']})
        assert restored.ok and restored.json()['restored']['count']==2
        assert len(client.get(base,timeout=2).json()['groups'])==4
        assert client.get(base,params={'action':'preview','keys':k2[0]},timeout=3).ok
        assert post('workspace_trash',json={'id':u2['upload']}).ok
        assert post('workspace_purge',json={'id':u2['upload']}).json()['purged']['count']==2
        assert not Path(db,f'upload-{u2["upload"]}.json').exists()
        assert len(client.get(base,timeout=2).json()['groups'])==2
        assert client.get(base,timeout=2).json()['recycle']==[]
        # Permanent delete must not affect another workspace.
        assert post('workspace_trash',json={'id':original_ws}).ok
        assert post('recycle_empty',json={}).json()['purged']['count']==2
        assert client.get(base,timeout=2).json()['groups']==[]
        assert client.get(base,timeout=2).json()['workspaces']==[]
        assert client.get(base,timeout=2).json()['recycle']==[]
        # Emptying an already empty recycle bin is harmless.
        assert post('recycle_empty',json={}).json()['purged']['count']==0
        # Expiry is request-driven: on the next authenticated call after the
        # 30-day deadline, the recycle records disappear automatically.
        fresh=post('upload',files={'workbook':('again.csv',source)}).json()
        rv=post('review',json={'upload':fresh['upload'],'map':fresh['map'],'rules':rules}).json()
        assert post('issue',json={'review':rv['review'],'keys':[rv['groups'][0]['key']]}).ok
        assert post('workspace_trash',json={'id':fresh['upload']}).ok
        saved_register=Path(db,'register.json')
        saved=json.loads(saved_register.read_text())
        for receipt in saved.values():
            if 'recycle' in receipt:
                receipt['recycle']['expires_at']='2001-01-01T00:00:00+00:00'
        saved_register.write_text(json.dumps(saved))
        assert client.get(base,timeout=2).json()['recycle']==[]
        assert client.get(base,timeout=2).json()['groups']==[]
        assert client.post(base,params={'action':'logout'},headers=hdr,json={},timeout=3).json()['signedOut'] is True
        assert client.get(base,params={'action':'session'},timeout=2).status_code==401
        print('PHP smoke test passed: upload, validation, preview, atomic issuance, saved register, duplicate rejection.')
    finally:
        server.terminate();server.wait(timeout=5)
