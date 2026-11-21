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
        # Print design configuration is versioned, private and admin-only.
        original_style=client.get(base,params={'action':'print_design'},timeout=3)
        assert original_style.ok,original_style.text
        settings=original_style.json()
        assert settings['version']==0 and settings['design']['default_layout']=='classic'
        assert settings['design']['default_qr'] is True
        assert client.post(base,params={'action':'print_design'},
                    headers={'X-LR-Studio':'1'},json=settings,timeout=3).status_code==403
        invalid=json.loads(json.dumps(settings['design']))
        invalid['classic']['header_pt']=90
        assert client.post(base,params={'action':'print_design'},headers=hdr,
                    json={'version':0,'design':invalid},timeout=3).status_code==422
        invalid['classic']['header_pt']=20
        invalid['classic']['accent']='red;page-break-before:always'
        assert client.post(base,params={'action':'print_design'},headers=hdr,
                    json={'version':0,'design':invalid},timeout=3).status_code==422
        editable=json.loads(json.dumps(settings['design']))
        editable['classic'].update({'header_pt':23,'accent':'#4338ca','font':'serif'})
        editable['clean'].update({'header_pt':19,'shade':'#ecfdf5','border':'strong'})
        editable['default_layout']='clean'
        editable['default_qr']=False
        updated=client.post(base,params={'action':'print_design'},headers=hdr,
                    json={'version':0,'design':editable},timeout=3)
        assert updated.ok and updated.json()['version']==1,updated.text
        assert client.post(base,params={'action':'print_design'},headers=hdr,
                    json={'version':0,'design':settings['design']},timeout=3).status_code==409
        assert client.get(base,params={'action':'session'},timeout=3).json()['print_defaults']=={'layout':'clean','qr':False}
        assert Path(db,'print-design.json').is_file()
        # Factory reset retains audit-friendly incremented version and original layout.
        reset=client.post(base,params={'action':'print_design'},headers=hdr,
                    json={'version':1,'design':settings['design']},timeout=3)
        assert reset.ok and reset.json()['version']==2
        assert client.get(base,params={'action':'session'},timeout=3).json()['print_defaults']=={'layout':'classic','qr':True}
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
        # Classic template: the baked K.G. heading is replaced with K.G./Ltr.,
        # litre measurements move into that column, and the product stays at
        # its existing bottom position with bold+italic typography.
        assert '<div class="classic-unit-heading">K.G./Ltr.</div>' in issued.text
        assert 'left:339pt;top:229.4pt;width:52pt;font-size:8pt">48 L</div>' in issued.text
        assert 'left:339pt;top:342.5pt;width:53pt;font-size:8pt">NANO UREA</div>' in issued.text
        assert 'class="field center bi"' in issued.text
        assert '· 48 L' not in issued.text
        assert 'Ref.No.:' not in issued.text or '00010001' in issued.text

        assert len(client.get(base,timeout=2).json()['groups'])==2
        # Visual WYSIWYG: editor sample and printed draft must use the SAME
        # saved LR values. No silent truncation to illustrative text.
        sample_response=client.get(base,params={'action':'visual_sample','key':g[0]['key']},timeout=7)
        assert sample_response.ok,sample_response.text
        sample_json=sample_response.json()
        assert sample_json['id']==g[0]['id'] and sample_json['key']==g[0]['key']
        assert sample_json['values']['lr_no']==g[0]['id']
        assert sample_json['values']['consignee']==g[0]['common']['consignee']
        assert sample_json['values']['transport_address']==g[0]['common']['address']
        assert sample_json['values']['consignor_address']==g[0]['common']['consignor_address']
        assert sample_json['values']['date']=='20-04-2026'
        assert sample_json['qr_mode'] in ('offline','link','unavailable')
        assert client.get(base,params={'action':'visual_sample','key':'nonexistent'},timeout=2).status_code==404
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
        # Admin visual designer: from a vector starter to a versioned PDF-ready design.
        assert client.get(base,params={'action':'visual_public'},timeout=2).json()=={'templates':[]}
        visual=client.get(base,params={'action':'visual_templates'},timeout=2).json()
        assert visual=={'revision':0,'templates':[]}
        created=post('visual_create',json={'revision':0,'base':'clean','name':'Dispatch Custom LR'})
        assert created.ok,created.text
        visual=created.json()
        assert visual['revision']==1 and len(visual['templates'])==1
        vt=visual['templates'][0];template_id=vt['id']
        assert vt['published'] is None and len(vt['draft'])>=30
        assert all('id' in layer for layer in vt['draft'])
        assert client.get(base,params={'action':'preview','layout':'visual:'+template_id,
                         'keys':g[0]['key']},timeout=5).status_code==422
        assert client.get(base,params={'action':'preview','layout':'visual:'+template_id,
                         'keys':g[0]['key'],'template_draft':'1'},timeout=5).ok
        moved=json.loads(json.dumps(vt['draft']))
        carrier_field=next(layer for layer in moved if layer['kind']=='field' and layer['binding']=='carrier')
        carrier_field.update({'x':108,'y':30,'bold':True,'italic':True,'font':'serif','size':22})
        invalid=json.loads(json.dumps(moved))
        invalid[0]['x']=590
        bad=post('visual_save',json={'revision':1,'id':template_id,'name':'Dispatch Custom LR','elements':invalid})
        assert bad.status_code==422
        saved=post('visual_save',json={'revision':1,'id':template_id,'name':'Dispatch Custom LR','elements':moved})
        assert saved.ok,saved.text
        assert saved.json()['revision']==2
        stale=post('visual_save',json={'revision':1,'id':template_id,'name':'Lost Edit','elements':moved})
        assert stale.status_code==409
        proof=client.get(base,params={'action':'preview','layout':'visual:'+template_id,
                'keys':','.join(j['keys']),'qr':'0','template_draft':'1'},timeout=5)
        assert proof.ok,proof.text[:500]
        assert proof.text.count('class="half visual')==2
        assert proof.text.count('class="sheet')==1
        assert 'data-layer="'+carrier_field['id']+'"' in proof.text
        assert 'font-style:italic' in proof.text
        assert 'class="vqr"' not in proof.text
        if os.environ.get('LR_RELEASE_SMOKE'):
            from pypdf import PdfReader
            from io import BytesIO
            proof_pdf=client.get(base,params={'action':'pdf','layout':'visual:'+template_id,
                      'keys':','.join(j['keys']),'template_draft':'1','qr':'0'},timeout=30)
            assert proof_pdf.ok,proof_pdf.text[:500] if not proof_pdf.ok else ''
            assert len(PdfReader(BytesIO(proof_pdf.content)).pages)==1
        published=post('visual_publish',json={'revision':2,'id':template_id,'confirm_warnings':True})
        assert published.ok,published.text
        assert published.json()['revision']==3
        vt=published.json()['templates'][0]
        assert vt['published']['version']==1 and len(vt['history'])==1
        published_list=client.get(base,params={'action':'visual_public'},timeout=2).json()['templates']
        assert published_list==[{'id':template_id,'name':'Dispatch Custom LR','version':1}]
        active=client.get(base,params={'action':'preview','layout':'visual:'+template_id,
               'keys':','.join(j['keys']),'qr':'0'},timeout=5)
        assert active.ok and active.text.count('class="sheet')==1
        assert active.text.count('class="half visual')==2
        assert all(active.text.count(one['id'])==1 for one in g[:2])
        assert '<img class="vqr"' not in active.text
        if os.environ.get('LR_RELEASE_SMOKE'):
            real_pdf=client.get(base,params={'action':'pdf','layout':'visual:'+template_id,
                      'keys':','.join(j['keys']),'qr':'0'},timeout=30)
            assert real_pdf.ok,real_pdf.text[:500] if not real_pdf.ok else ''
            pages=PdfReader(BytesIO(real_pdf.content)).pages
            assert len(pages)==1
            assert all(one['id'] in pages[0].extract_text() for one in g[:2])
        assert post('visual_delete',json={'revision':3,'id':template_id}).status_code==409
        updated_version=json.loads(json.dumps(vt['draft']))
        updated_version[0]['text']='SECOND EDITION'
        assert post('visual_save',json={'revision':3,'id':template_id,
                    'name':'Dispatch Custom LR','elements':updated_version}).ok
        assert post('visual_publish',json={'revision':4,'id':template_id,
                    'confirm_warnings':True}).ok
        assert client.get(base,params={'action':'visual_public'},timeout=2).json()['templates'][0]['version']==2
        historical=client.get(base,params={'action':'preview','layout':'visual:'+template_id,
                  'template_version':'1','keys':g[0]['key'],'qr':'0'},timeout=4)
        assert historical.ok and 'SECOND EDITION' not in historical.text
        assert post('visual_revert',json={'revision':5,'id':template_id,'version':1}).ok
        assert client.get(base,params={'action':'visual_templates'},timeout=3).json()['templates'][0]['draft'][0]['text'] != 'SECOND EDITION'
        style_config=client.get(base,params={'action':'print_design'},timeout=2).json()
        design=json.loads(json.dumps(style_config['design']))
        design['default_layout']='clean'
        design['default_qr']=False
        design['classic'].update({'header_pt':23,'accent':'#4338ca','font':'serif'})
        design['clean'].update({'header_pt':19,'shade':'#ecfdf5','border':'strong','accent':'#047857'})
        result=post('print_design',json={'version':style_config['version'],'design':design})
        assert result.ok,result.text
        default_preview=client.get(base,params={'action':'preview','keys':g[0]['key']},timeout=3)
        assert default_preview.ok and 'data-layout="clean"' in default_preview.text
        assert 'background:#ecfdf5' in default_preview.text
        assert '#047857' in default_preview.text
        assert '<img class="lr-qr"' not in default_preview.text
        custom_classic=client.get(base,params={'action':'preview','keys':g[0]['key'],'layout':'classic','qr':'1'},timeout=3)
        assert custom_classic.ok and '#4338ca' in custom_classic.text
        assert 'DejaVu Serif' in custom_classic.text
        assert custom_classic.text.count('<img class="lr-qr"')==1
        assert client.get(base,params={'action':'print_design'},timeout=2).json()['version']==3
        assert post('print_design',json={'version':3,'design':style_config['design']}).ok
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
            classic_text=page.extract_text()
            assert 'K.G./Ltr.' in classic_text
            assert '48 L' in classic_text
            assert 'NANO\nUREA' in classic_text  # 53pt bottom field wraps as before
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
        # The primary auth.json operator is admin; generated accounts are download-only.
        session=client.get(base,params={'action':'session'},timeout=2).json()
        assert session['role']=='admin'
        users=client.get(base,params={'action':'users'},timeout=2)
        assert users.ok and users.json()=={'admin':'operator','users':[]}
        no_qr=client.get(base,params={'action':'preview','layout':'classic',
                          'qr':'0','keys':','.join(j['keys'])},timeout=5)
        assert no_qr.ok and no_qr.text.count('<div class="half')==2
        assert '<img class="lr-qr"' not in no_qr.text
        assert 'Full LR exceeds print QR capacity.' not in no_qr.text
        no_qr_clean=client.get(base,params={'action':'preview','layout':'clean',
                                'qr':'0','keys':','.join(j['keys'])},timeout=5)
        assert no_qr_clean.ok and '<img class="lr-qr"' not in no_qr_clean.text
        assert client.get(base,params={'action':'preview','qr':'maybe',
                          'keys':g[0]['key']},timeout=2).status_code==422
        if os.environ.get('LR_RELEASE_SMOKE'):
            from io import BytesIO
            from pypdf import PdfReader
            no_qr_pdf=client.get(base,params={'action':'pdf','qr':'0',
                              'keys':','.join(j['keys'])},timeout=30)
            assert no_qr_pdf.ok and len(PdfReader(BytesIO(no_qr_pdf.content)).pages)==1

        created=post('user_create',json={'username':'dispatch07',
                     'password':'superlong-download-password-456','workspaces':[],
                     'enabled':True})
        assert created.status_code==201,created.text
        assert created.json()['user']['role']=='download'
        assert created.json()['user']['workspaces']==[]
        assert 'password_hash' not in created.text and 'password' not in created.text
        private_users=json.loads(Path(db,'users.json').read_text())
        assert private_users['dispatch07']['password_hash'].startswith('$2')
        assert 'superlong-download-password-456' not in Path(db,'users.json').read_text()
        downloader=requests.Session()
        def login_down(password='superlong-download-password-456'):
            r=downloader.post(base,params={'action':'login'},
                              headers={'X-LR-Studio':'1'},
                              json={'username':'dispatch07','password':password},timeout=8)
            assert r.ok,r.text
            assert r.json()['role']=='download'
            return r.json()['csrf']
        download_csrf=login_down()
        assert downloader.get(base,timeout=2).json()['groups']==[]
        assert downloader.get(base,params={'action':'users'},timeout=2).status_code==403
        assert downloader.get(base,params={'action':'settings'},timeout=2).status_code==403
        assert downloader.get(base,params={'action':'print_design'},timeout=2).status_code==403
        assert downloader.get(base,params={'action':'visual_templates'},timeout=2).status_code==403
        assert downloader.get(base,params={'action':'visual_sample','key':g[0]['key']},timeout=2).status_code==403
        assert downloader.get(base,params={'action':'visual_public'},timeout=2).json()['templates'][0]['id']==template_id
        for protected in ['visual_create','visual_save','visual_publish','visual_revert','visual_delete']:
            assert downloader.post(base,params={'action':protected},
                  headers={'X-LR-Studio':'1','X-LR-CSRF':download_csrf},
                  json={'revision':0},timeout=3).status_code==403

        for action in ['upload','review','issue','workspace_trash','workspace_restore',
                       'workspace_purge','recycle_empty','settings','print_design','user_create',
                       'user_update','user_delete','suggest']:
            forbidden=downloader.post(base,params={'action':action},
                        headers={'X-LR-Studio':'1','X-LR-CSRF':download_csrf},
                        json={},timeout=3)
            assert forbidden.status_code==403,(action,forbidden.text)
        assert downloader.get(base,params={'action':'preview','keys':g[0]['key'],
                          'review':r['review']},timeout=3).status_code==403
        # Grant one workspace; all GET routes must check membership, not just the UI.
        granted=post('user_update',json={'username':'dispatch07','enabled':True,
                     'workspaces':[original_ws]})
        assert granted.ok,granted.text
        viewer=downloader.get(base,timeout=3).json()
        assert len(viewer['groups'])==2 and len(viewer['workspaces'])==1
        assert viewer['workspaces'][0]['id']==original_ws and viewer['recycle']==[]
        assert downloader.get(base,params={'action':'preview','keys':g[0]['key']},timeout=4).ok
        assert downloader.get(base,params={'action':'preview','layout':'visual:'+template_id,
                    'keys':g[0]['key'],'qr':'0'},timeout=5).ok
        assert downloader.get(base,params={'action':'preview','layout':'visual:'+template_id,
                    'keys':g[0]['key'],'template_draft':'1'},timeout=5).status_code==403
        assert downloader.get(base,params={'action':'preview','layout':'visual:'+template_id,
                    'keys':g[0]['key'],'template_version':'1'},timeout=5).status_code==403

        assert downloader.get(base,params={'action':'details','key':g[0]['key']},timeout=3).ok
        assert downloader.get(base,params={'action':'pdf','qr':'0',
                          'keys':g[0]['key']},timeout=30).content.startswith(b'%PDF')
        for action in ['preview','pdf','details']:
            denied=downloader.get(base,params={'action':action,
                         'key':k2[0],'keys':k2[0]},timeout=5)
            assert denied.status_code==404,(action,denied.status_code)
        assert downloader.get(base,params={'action':'pdf',
                          'keys':','.join([g[0]['key'],k2[0]])},timeout=5).status_code==404
        # Existing sessions must use updated grants, not an old access token.
        changed=post('user_update',json={'username':'dispatch07','enabled':True,
                     'workspaces':[u2['upload']]})
        assert changed.ok
        assert downloader.get(base,params={'action':'preview','keys':g[0]['key']},timeout=3).status_code==404
        assert downloader.get(base,params={'action':'preview','keys':k2[0]},timeout=3).ok
        assert len(downloader.get(base,timeout=2).json()['groups'])==2
        # Password reset invalidates old cookies; disabled and deleted users too.
        rotated='another-long-download-password-789'
        assert post('user_update',json={'username':'dispatch07','enabled':True,
                       'workspaces':[u2['upload']],'password':rotated}).ok
        assert downloader.get(base,timeout=2).status_code==401
        assert downloader.post(base,params={'action':'login'},headers={'X-LR-Studio':'1'},
                     json={'username':'dispatch07','password':'superlong-download-password-456'},
                     timeout=8).status_code==401
        download_csrf=login_down(rotated)
        assert post('user_update',json={'username':'dispatch07','enabled':False,
                       'workspaces':[u2['upload']]}).ok
        assert downloader.get(base,timeout=2).status_code==401
        assert downloader.post(base,params={'action':'login'},headers={'X-LR-Studio':'1'},
                         json={'username':'dispatch07','password':rotated},
                         timeout=8).status_code==401
        assert post('user_update',json={'username':'dispatch07','enabled':True,
                       'workspaces':[u2['upload']]}).ok
        download_csrf=login_down(rotated)
        assert post('user_delete',json={'username':'dispatch07'}).ok
        assert downloader.get(base,timeout=2).status_code==401
        assert downloader.get(base,params={'action':'details','key':k2[0]},timeout=2).status_code==401
        assert client.get(base,params={'action':'users'},timeout=2).json()['users']==[]
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
