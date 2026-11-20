"""Encode private dispatch details locally; stdin/stdout only."""
import base64
import io
import json
import sys

import qrcode
from qrcode.exceptions import DataOverflowError


def encode(payload):
    qr = qrcode.QRCode(version=None, error_correction=qrcode.constants.ERROR_CORRECT_M,
                       box_size=5, border=4)
    try:
        qr.add_data(payload)
        qr.make(fit=True)
    except DataOverflowError:
        return None
    # At 84 pt this keeps each printed module roughly 0.30 mm or wider.
    if qr.version > 18:
        return None
    out = io.BytesIO()
    qr.make_image(fill_color='black', back_color='white').save(out, format='PNG')
    return base64.b64encode(out.getvalue()).decode('ascii')


result = []
for entry in json.load(sys.stdin):
    png = encode(entry['text'])
    mode = 'offline'
    if not png and entry.get('url'):
        png, mode = encode(entry['url']), 'link'
    result.append({'png': png, 'mode': mode if png else 'unavailable'})
json.dump(result, sys.stdout)
