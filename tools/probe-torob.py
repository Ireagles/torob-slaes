#!/usr/bin/env python3
"""Read-only public Torob feasibility; real output never implies freshness."""
import argparse, datetime, json, urllib.request, urllib.parse, urllib.error
from html.parser import HTMLParser
p=argparse.ArgumentParser();p.add_argument('url',nargs='?',default='https://torob.com/');p.add_argument('--output');a=p.parse_args()
u=urllib.parse.urlsplit(a.url)
if u.scheme!='https' or u.hostname not in ('torob.com','www.torob.com') or u.username or u.password or u.port not in (None,443) or u.query or u.fragment:p.error('Only public HTTPS Torob URLs allowed')
if u.path not in ('','/') and not u.path.startswith('/p/'):p.error('Only home or product pages; no automated search')
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):return None
class JsonLd(HTMLParser):
    def __init__(self):super().__init__();self.on=False;self.blocks=[];self.current=''
    def handle_starttag(self,t,attrs):
        if t=='script':self.on=dict(attrs).get('type')=='application/ld+json';self.current=''
    def handle_endtag(self,t):
        if t=='script' and self.on:self.blocks.append(self.current);self.on=False
    def handle_data(self,data):
        if self.on:self.current+=data
result={'provider':'torob','source_url':a.url,'source_observed_at':None,'cache_age_seconds':None,'freshness':'unknown','coverage':'partial','offers':[],'is_test':False,'parser_version':'public-jsonld-1','billable_units':None}
try:
    req=urllib.request.Request(a.url,headers={'User-Agent':'ViratelSmartPricing/0.1 read-only feasibility'})
    with urllib.request.build_opener(NoRedirect).open(req,timeout=15) as r:
        body=r.read(1048577);result.update(http_status=r.status,bytes=len(body),status='schema_unverified' if len(body)<=1048576 else 'response_too_large')
        if len(body)<=1048576:
            parser=JsonLd();parser.feed(body.decode('utf-8'))
            for block in parser.blocks:
                data=json.loads(block)
                if data.get('@type')!='Product':continue
                result.update(canonical_product_id=data['sku'],source_title=data['name'],currency=data['offers']['priceCurrency'],status='ok')
                for offer in data['offers'].get('offers',[]):
                    result['offers'].append({'seller_name':offer['name'],'store_id':None,'seller_url':'','price':offer['price'],'currency':offer['priceCurrency'],'in_stock':True if offer.get('availability')=='http://schema.org/InStock' else None,'observed_at':None,'attributes':{},'shipping':None,'confidence':{'level':'unverified','reasons':['source_time_unknown','seller_identity_unknown','quality_unknown']}})
except (urllib.error.URLError,OSError,ValueError,KeyError) as e:result.update(status='access_failed',error=str(e.reason if hasattr(e,'reason') else e))
result['fetched_at']=datetime.datetime.now(datetime.timezone.utc).isoformat()
s=json.dumps(result,ensure_ascii=False,indent=2);print(s)
if a.output:open(a.output,'w').write(s+'\n')
