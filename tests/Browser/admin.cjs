const {chromium}=require('playwright');const fs=require('fs');
(async()=>{
 const creds=JSON.parse(fs.readFileSync('/workspace/vsp-runtime/ui-credentials.json','utf8'));
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});const page=await browser.newPage({viewport:{width:1440,height:1000}});const errors=[];page.on('pageerror',e=>errors.push(e.message));const out={browser:'Playwright Chromium',fallback:'Browser plugin not available',checks:[]};
 function ok(v,n){if(!v)throw new Error(n);out.checks.push({name:n,passed:true});}
 await page.goto('http://127.0.0.1:8090/wp-login.php');await page.locator('#user_login').fill(creds.username);await page.locator('#user_pass').fill(creds.password);await page.locator('#wp-submit').click();await page.waitForURL('**/wp-admin/**');
 const url='http://127.0.0.1:8090/wp-admin/admin.php?page=viratel-smart-pricing';await page.goto(url);ok((await page.title()).includes('ویراتل'),'page identity');ok(await page.locator('.vsp-wrap h1').isVisible(),'meaningful page content');ok((await page.locator('.vsp-status').innerText()).includes('پایش'),'installation mode rendered');
 await page.getByRole('button',{name:'توقف اضطراری اعمال قیمت',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('result')==='ok');ok((await page.locator('.vsp-status').innerText()).includes('توقف فعال است'),'pause updates actual UI state');await page.getByRole('button',{name:'رفع توقف (بدون رفع موانع داده)',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('result')==='ok');ok((await page.locator('.vsp-status').innerText()).includes('توقف غیرفعال است'),'resume state');
 await page.screenshot({path:'/workspace/scratch/vsp-dashboard-desktop.png',fullPage:false});
 await page.goto(url+'&tab=products');await page.getByPlaceholder('جستجوی SKU، مدل، عنوان یا کیفیت').fill('vsp-bench');await page.getByRole('button',{name:'جستجو',exact:true}).click();ok((await page.locator('.vsp-table').innerText()).includes('vsp-bench'),'SKU search renders paginated products');
 // Edit an existing local mapping, check persisted result and optimistic conflict.
 await page.goto(url+'&tab=products&edit=35');
 const form=page.locator('form').filter({has:page.locator('input[name="operation"][value="mapping"]')});
 ok(await form.locator('input[name="floor"]').inputValue()==='450000','editor preserves existing floor and currency');
 const stale=await form.evaluate(el=>Object.fromEntries(new FormData(el)));
 await form.locator('input[name="delta"]').fill('1001');
 await form.getByRole('button',{name:'ذخیره تنظیمات؛ بدون تغییر قیمت',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('result')==='ok');
 await page.goto(url+'&tab=products&edit=35');
 ok(await page.locator('input[name="delta"]').inputValue()==='1001','form edit persists');
 const conflict=await page.request.post('http://127.0.0.1:8090/wp-admin/admin-post.php',{form:stale});
 ok(conflict.url().includes('result=mapping_conflict'),'stale editor refuses overwrite');
 const bad=await page.request.post('http://127.0.0.1:8090/wp-admin/admin-post.php',{form:{action:'vsp_action',operation:'pause',_wpnonce:'invalid'}});
 ok(bad.status()===403,'invalid admin nonce rejected');
 await page.goto(url+'&tab=products&edit=35');await page.locator('input[name="delta"]').fill('1000');await page.getByRole('button',{name:'ذخیره تنظیمات؛ بدون تغییر قیمت',exact:true}).click();await page.waitForURL(u=>u.searchParams.get('result')==='ok');
 for(const tab of ['sources','rules','proposals','queue','history','csv','settings']){await page.goto(url+'&tab='+tab);ok(await page.locator('.vsp-wrap h1').isVisible(),tab+' renders');ok(!(await page.locator('body').innerText()).includes('Fatal error'),tab+' has no fatal overlay');}
 await page.setViewportSize({width:390,height:844});await page.goto(url);await page.screenshot({path:'/workspace/scratch/vsp-dashboard-mobile.png',fullPage:false});ok((await page.locator('.vsp-cards article').count())===4,'mobile cards render');ok(await page.locator('.vsp-wrap').evaluate(e=>e.scrollWidth<=e.clientWidth+2),'mobile dashboard no horizontal overflow');ok(errors.length===0,'no JavaScript page errors');
 await page.goto('http://127.0.0.1:8090/');ok(await page.locator('link[href*="viratel-smart-pricing"]').count()===0,'plugin CSS not loaded in storefront');out.errors=errors;fs.writeFileSync('/workspace/torob-slaes/tests/results/ui.json',JSON.stringify(out,null,2));await browser.close();console.log(JSON.stringify(out,null,2));
})().catch(e=>{console.error(e.message);process.exit(1)});
