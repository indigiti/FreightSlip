from pathlib import Path
import tempfile, subprocess, time, os, socket, re
import requests

root = Path(__file__).resolve().parents[1]
with socket.socket() as s:
    s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
base=f'http://127.0.0.1:{port}/index.php'
with tempfile.TemporaryDirectory() as db:
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root/'php/web')],env={**os.environ,'LR_DATA_DIR':db},stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        for _ in range(40):
            try:
                assert requests.get(base,timeout=1).json()['groups']==[];break
            except requests.ConnectionError: time.sleep(.05)
        hdr={'X-LR-Studio':'1'}
        def post(action,**kwargs): return requests.post(base,params={'action':action},headers=hdr,timeout=5,**kwargs)
        with (root/'public/example-dispatch.csv').open('rb') as f:
            u=post('upload',files={'workbook':f}).json()
        assert u['rows']==3 and u['map']['freight']==7 and u['map']['address']==11
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
        assert post('issue',json={'review':r['review'],'keys':[g[0]['key'],g[2]['key']]}).status_code==422
        assert requests.get(base,timeout=2).json()['groups']==[]
        p=requests.get(base,params={'action':'preview','keys':g[0]['key'],'review':r['review']},timeout=2)
        assert p.ok and p.text.count('DRAFT · NOT ISSUED')==1 and p.text.count('370.00')==1 and '48 L' in p.text
        assert p.text.count('<div class="sheet')==1 and 'A4 portrait' in p.text
        if os.environ.get('LR_SAMPLE_HTML'): Path(os.environ['LR_SAMPLE_HTML']).write_text(p.text)
        j={'review':r['review'],'keys':[g[0]['key'],g[1]['key']]}
        assert post('issue',json=j).status_code==201
        assert post('issue',json=j).status_code==409
        assert post('review',json=data).json()['groups'][0]['status']=='issued'
        assert post('review',json={**data,'rules':{**rules,'allow_zero':True}}).json()['groups'][2]['status']=='ready'
        issued=requests.get(base,params={'action':'preview','keys':g[0]['key']},timeout=2)
        assert issued.ok and 'DRAFT · NOT ISSUED' not in issued.text and '370.00' in issued.text
        assert len(requests.get(base,timeout=2).json()['groups'])==2
        layout=requests.get(base,params={'action':'preview','keys':','.join(j['keys'])},timeout=2)
        assert layout.ok and layout.text.count('<div class="sheet')==1 and layout.text.count('<div class="half')==2
        assert all(layout.text.count(x['id'])==1 for x in g[:2])
        assert issued.text.count('<div class="half')==1
        assert requests.get(base,params={'action':'preview','keys':','.join([g[0]['key']]*2)},timeout=2).status_code==422
        qrs=re.findall(r'<img class="lr-qr"[^>]*base64,([^"\s]+)',layout.text)
        assert len(qrs)==2 and qrs[0]!=qrs[1]
        details=requests.get(base,params={'action':'details','key':g[0]['key']},timeout=2)
        assert details.ok and 'text/plain' in details.headers['Content-Type']
        assert 'OBD: 00010001' in details.text and 'Total freight (INR): 370.00' in details.text
        assert requests.get(base,params={'action':'details','key':g[2]['key']},timeout=2).status_code==404
        if os.environ.get('LR_BATCH_HTML'): Path(os.environ['LR_BATCH_HTML']).write_text(layout.text)
        assert requests.post(base,params={'action':'issue'},json=j,timeout=2).status_code==403
        assert requests.get(base,params={'action':'pdf','keys':g[0]['key'],'review':r['review']},timeout=2).status_code==422
        print('PHP smoke test passed: upload, validation, preview, atomic issuance, saved register, duplicate rejection.')
    finally:
        server.terminate();server.wait(timeout=5)
