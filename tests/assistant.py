from pathlib import Path
import sys
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'ai'))
from main import app
from fastapi.testclient import TestClient
client=TestClient(app)
r=client.post('/suggest',json={'headers':['LR NO','Frieght',''], 'fields':{'lr_no':['LR NO'],'freight':['FREIGHT']},'mapped':{'lr_no':0}})
assert r.status_code==200 and r.json()['suggestions'][0]['column']==1, r.text
print('FastAPI smoke test passed: fuzzy suggestion with exact mappings excluded.')
