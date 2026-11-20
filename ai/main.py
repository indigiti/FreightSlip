"""Header-only similarity assistant; returns proposals, not authoritative mappings."""
import re
from difflib import SequenceMatcher
from fastapi import FastAPI
from pydantic import BaseModel, Field

app = FastAPI(title="LR header mapping assistant", docs_url=None, redoc_url=None)

class Sheet(BaseModel):
    headers: list[str] = Field(max_length=80)
    fields: dict[str, list[str]]
    mapped: dict[str, int]

def normal(text: str) -> str:
    words = re.findall(r"[a-z0-9]+", text.lower())
    aliases = {"number":"no", "name":"", "shipment":"shipping", "wt":"weight", "ltr":"litres", "ltrs":"litres", "ship":"shipping", "dest":"destination"}
    return " ".join(sorted(word for w in words if (word := aliases.get(w, w))))

@app.get('/health')
def health():
    return {'ok': True}

@app.post('/suggest')
def suggest(sheet: Sheet):
    pairs = []
    for field, aliases in sheet.fields.items():
        if field in sheet.mapped:
            continue
        options = []
        for i, header in enumerate(sheet.headers):
            if not normal(header) or i in sheet.mapped.values():
                continue
            score = max(SequenceMatcher(None, normal(header), normal(alias)).ratio() for alias in aliases)
            options.append((score, i))
        options.sort(reverse=True)
        if options and options[0][0] >= .78 and (len(options) == 1 or options[0][0] - options[1][0] >= .08):
            score, column = options[0]
            pairs.append((score, field, column))
    used = set()
    suggestions = []
    for score, field, col in sorted(pairs, reverse=True):
        if col not in used and not any(c == col and f != field and abs(s-score) < .08 for s,f,c in pairs):
            suggestions.append({'field':field,'column':col,'score':round(score,2)})
            used.add(col)
    return {'suggestions': suggestions}
