/* Mizban - Admin JS v6.0 (bilingual fa/en) */
(function(){
'use strict';
const API='api.php', $=(s,p=document)=>p.querySelector(s), $$=(s,p=document)=>[...p.querySelectorAll(s)];
let M=window.MIZBAN||{loggedIn:false,lang:'fa',i18n:{},i18nFa:{},providers:[]};
let curView='dashboard', reqFilter='', logFilter='', keyProviderFilter='';

/* ---------- i18n ---------- */
function __(k,vars){
    let s=(M.i18n&&M.i18n[k])||(M.i18nFa&&M.i18nFa[k])||k;
    if(vars)for(const key in vars)s=s.split('{'+key+'}').join(vars[key]);
    return s;
}

async function api(a,o={}){
    const qs=new URLSearchParams({...((o.qs)||{}),lang:M.lang});
    const u=`${API}?action=${a}&${qs.toString()}`;
    const r=await fetch(u,{method:o.method||'GET',headers:{'Content-Type':'application/json',...(o.headers||{})},body:o.body?JSON.stringify(o.body):undefined,credentials:'same-origin'});
    let d;try{d=await r.json()}catch{d={error:__('toast.invalidResponse')}}
    if(!r.ok&&!d.error)d.error=`HTTP ${r.status}`;
    return d;
}
function toast(m,t=''){const e=$('#toast');if(!e)return;e.textContent=m;e.className='toast show '+t;clearTimeout(e._t);e._t=setTimeout(()=>e.className='toast '+t,3500)}
function copy(t){navigator.clipboard.writeText(t).then(()=>toast(__('toast.copied'),'success'))}
function esc(s){return s==null?'':String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function fmt(s){return s?s.replace('T',' ').substring(0,19):'-'}
function pBadge(p){p=Number(p)||0;const c=p>=9?'p-max':p>=7?'p-high':p>=4?'p-mid':'p-low';return `<span class="priority-badge ${c}">${p}</span>`}
function sPill(s){const l={queued:__('status.queued'),running:__('status.running'),processing:__('status.processing'),completed:__('status.completed'),failed:__('status.failed'),dead:__('status.dead'),retry:__('status.retry'),delayed:__('status.delayed')};const cls=(s==='running'||s==='processing')?'sp-running':('sp-'+s);return `<span class="status-pill ${cls}">${l[s]||s}</span>`}
function modeBadge(mode){return mode==='general'?'<span class="badge" style="background:rgba(139,92,246,.15);color:#8b5cf6;font-size:9px;padding:2px 6px">GENERAL</span>':'<span class="badge" style="background:rgba(59,130,246,.15);color:#3b82f6;font-size:9px;padding:2px 6px">SPECIFIC</span>'}
function modelDisplay(model){return model==='__general__'?__('model.auto'):esc(model)}

const VIEW_TITLES={dashboard:'nav.dashboard',requests:'nav.requests',clients:'nav.clients',providers:'nav.providers',keys:'nav.keys',models:'nav.models',logs:'nav.logs',test:'nav.test',docs:'nav.docs'};
function switchView(v){
    curView=v; $$('.view').forEach(x=>x.classList.remove('active')); $('#view-'+v)?.classList.add('active');
    $$('.nav-item').forEach(n=>n.classList.toggle('active',n.dataset.view===v));
    $('#viewTitle').textContent=__(VIEW_TITLES[v]||v); $('#sidebar')?.classList.remove('open');
    if(v==='dashboard')loadDash(); if(v==='requests')loadReqs(); if(v==='clients')loadClients();
    if(v==='providers')loadProviders(); if(v==='keys')loadKeys(); if(v==='models')loadModels();
    if(v==='logs')loadLogs();
}

async function checkSession(){
    if(M.loggedIn){showLoggedIn();return}
    try{
        const d=await api('admin_session');
        if(d&&d.logged_in){M.loggedIn=true;showLoggedIn()}
        else showLogin();
    }catch(e){showLogin()}
}
function showLogin(){
    M.loggedIn=false;
    const gate=$('#loginGate'); if(gate) gate.style.display='flex';
    const app=$('#appShell'); if(app) app.style.display='none';
    const login=$('#view-login'); if(login){ login.classList.add('active'); login.style.display=''; }
}
function showLoggedIn(){
    M.loggedIn=true;
    const gate=$('#loginGate'); if(gate) gate.style.display='none';
    const app=$('#appShell'); if(app) app.style.display='';
    const btn=$('#btnLogout'); if(btn) btn.style.display='block';
    switchView(curView||'dashboard');
}

/* ---------- Login ---------- */
$('#loginForm')?.addEventListener('submit',async e=>{
    e.preventDefault();
    const f=new FormData(e.target);
    const b=e.target.querySelector('button');
    b.disabled=true;b.textContent='...';
    try{
        const d=await api('admin_login',{method:'POST',body:{username:f.get('username'),password:f.get('password')}});
        b.disabled=false;b.textContent=__('login.submit');
        if(d&&d.ok){
            toast(__('toast.loginOk'),'success');
            showLoggedIn();
            return;
        }
        toast(d&&d.error?d.error:__('login.failed'),'error');
    }catch(err){
        b.disabled=false;b.textContent=__('login.submit');
        toast(__('toast.network'),'error');
    }
});
$('#btnLogout')?.addEventListener('click',async()=>{
    try{await api('admin_logout');}catch(e){}
    showLogin();
    toast(__('toast.logout'));
});

/* ---------- Change password ---------- */
$('#btnPwd')?.addEventListener('click',()=>{$('#pwdForm').reset();openModal('pwdModal')});
$('#pwdForm')?.addEventListener('submit',async e=>{
    e.preventDefault();const f=new FormData(e.target);
    const cur=f.get('current'),pw=f.get('new'),cf=f.get('confirm');
    if(pw!==cf)return toast(__('pwd.mismatch'),'error');
    if(String(pw).length<8)return toast(__('pwd.tooShort'),'error');
    const b=$('#pwdForm button[type=submit]');b.disabled=true;
    const d=await api('admin_change_password',{method:'POST',body:{current_password:cur,new_password:pw}});
    b.disabled=false;
    if(d&&d.ok){toast(__('pwd.success'),'success');closeModal('pwdModal')}
    else toast(d&&d.error?d.error:__('pwd.wrong'),'error')
});

/* ---------- Dashboard ---------- */
async function loadDash(){
    const sd=await api('stats');if(sd.stats){
        const s=sd.stats;const cards=[['queued',s.queued,'⏳'],['running',(s.running??s.processing??0),'⚙️'],['completed',s.completed,'✅'],['failed',s.failed,'❌'],['total',s.total,'📦']];
        $('#statsGrid').innerHTML=cards.map(c=>{const label=__(c[0]==='queued'?'stat.queued':c[0]==='running'?'stat.processing':c[0]==='completed'?'stat.completed':c[0]==='failed'?'stat.failed':'stat.total');return `<div class="stat-card stat-${c[0]}"><div class="stat-icon">${c[2]}</div><div class="stat-body"><div class="stat-label">${label}</div><div class="stat-value">${c[1]}</div></div></div>`}).join('');
    }
    const ad=await api('advanced_stats');
    if(ad.data){
        const d=ad.data;
        $('#advStatsGrid').innerHTML=`<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px">
            <div class="stat-card"><div class="stat-body"><div class="stat-label">🔑 ${__('adv.activeKeys')}</div><div class="stat-value" style="color:#10b981;font-size:18px">${d.active_keys}</div></div></div>
            <div class="stat-card"><div class="stat-body"><div class="stat-label">👥 ${__('adv.clients')}</div><div class="stat-value" style="color:#3b82f6;font-size:18px">${d.clients}</div></div></div>
            <div class="stat-card"><div class="stat-body"><div class="stat-label">📈 ${__('adv.lastHour')}</div><div class="stat-value" style="color:#f59e0b;font-size:18px">${d.last_hour_requests}</div></div></div>
            <div class="stat-card"><div class="stat-body"><div class="stat-label">⚡ ${__('adv.batchSize')}</div><div class="stat-value" style="color:#8b5cf6;font-size:18px">${d.batch_size}</div></div></div>
        </div>`;
    }
    const rd=await api('admin_requests',{qs:{page:1,per_page:6}});
    const box=$('#dashRequests');
    if(rd.requests&&rd.requests.length)box.innerHTML=rd.requests.map(r=>{
        return `<div class="req-row"><div><div class="font-semibold text-sm">#${r.id} ${modeBadge(r.mode)} · <span style="font-family:monospace;font-size:11px">${modelDisplay(r.model)}</span></div><div class="text-xs muted">${esc(r.client_name||'-')} ${r.provider_slug?'· '+esc(r.provider_slug):''} · ${fmt(r.created_at)}</div></div><div>${pBadge(r.priority)}${sPill(r.status)}</div></div>`;
    }).join('');
    else box.innerHTML=`<div class="muted" style="text-align:center;padding:20px">${__('dash.noRequests')}</div>`;
    const pb=$('#dashProviders');
    if(ad.data&&ad.data.providers_load){
        const loads=ad.data.providers_load;const maxLoad=Math.max(...loads.map(x=>x.load_count),1);
        pb.innerHTML=loads.map(p=>{
            const pct=Math.max((p.load_count/maxLoad)*100,2);
            return `<div style="margin-bottom:10px"><div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px"><span>${esc(p.name)} <span style="background:rgba(139,92,246,.15);color:#8b5cf6;font-size:10px;padding:1px 6px;border-radius:8px">${__('dash.priority')}: ${p.priority||100}</span></span><span class="muted">${__('dash.load')}: <b style="color:#f59e0b">${p.load_count}</b></span></div><div style="height:6px;background:#0f1117;border-radius:3px;overflow:hidden"><div style="height:100%;background:linear-gradient(to left,#10b981,#f59e0b);width:${pct}%"></div></div></div>`;
        }).join('');
    }
}
$('#btnRefreshDash')?.addEventListener('click',()=>{loadDash();toast(__('toast.updated'))});
$('#btnProcessQueue')?.addEventListener('click',async()=>{
    const b=$('#btnProcessQueue');b.disabled=true;b.textContent='...';
    const d=await api('process');b.disabled=false;b.textContent='▶️ '+__('btn.processQueueManual');
    if(d.ok){$('#processResult').innerHTML=`<span class="badge badge-ok">${__('toast.processed',{n:d.processed})}</span>`;toast(__('toast.processed',{n:d.processed}),'success');loadDash()}
    else{$('#processResult').innerHTML=`<span class="badge badge-error">${esc(d.error||__('login.failedShort'))}</span>`;toast(d.error,'error')}
});
$('#btnCleanup')?.addEventListener('click',async()=>{const d=await api('cleanup');if(d.ok){toast(`${__('toast.cleaned')} (${d.deleted_old_requests}+${d.reset_cooldowns})`,'success');loadDash()}else toast(d.error||__('login.failedShort'),'error')});

/* ---------- Requests ---------- */
async function loadReqs(){
    const tb=$('#reqTableBody');tb.innerHTML=`<tr><td colspan="10" class="loading">${__('generic.loading')}</td></tr>`;
    const d=await api('admin_requests',{qs:{page:1,per_page:100,status:reqFilter}});
    if(!d.requests){tb.innerHTML=`<tr><td colspan="10" class="loading">${esc(d.error||__('login.failedShort'))}</td></tr>`;return}
    if(!d.requests.length){tb.innerHTML=`<tr><td colspan="10" class="empty">${__('generic.empty')}</td></tr>`;return}
    tb.innerHTML=d.requests.map(r=>{
        return `<tr><td>${r.id}</td><td>${esc(r.client_name||'-')}<br><small class="muted">${esc(r.client_code||'')}</small></td><td>${modeBadge(r.mode)}</td><td><code>${modelDisplay(r.model)}</code></td><td><code>${esc(r.provider_slug||'-')}</code></td><td>${pBadge(r.priority)}</td><td>${sPill(r.status)}</td><td class="text-xs">${esc(r.fallback_used||'-')}</td><td class="text-xs muted">${fmt(r.created_at)}</td><td><button class="copy-btn" onclick="MIZBAN.viewReq(${r.id})">${__('req.details')}</button>${r.status==='failed'||r.status==='completed'?`<button class="copy-btn" onclick="MIZBAN.reproc(${r.id})" title="${__('req.reprocess')}">↻</button>`:''}</td></tr>`;
    }).join('');
}
$('#reqFilter')?.addEventListener('change',e=>{reqFilter=e.target.value;loadReqs()});

/* ---------- Clients ---------- */
async function loadClients(){
    const tb=$('#clientTableBody');tb.innerHTML=`<tr><td colspan="5" class="loading">${__('generic.loading')}</td></tr>`;
    const d=await api('admin_clients');
    if(!d.clients){tb.innerHTML=`<tr><td colspan="5">${esc(d.error)}</td></tr>`;return}
    tb.innerHTML=d.clients.map(c=>`<tr><td>${esc(c.name)}</td><td><code>${esc(c.code)}</code> <button class="copy-btn" onclick="copy('${esc(c.code)}')">📋</button></td><td>${pBadge(c.degree)}</td><td>${c.status==1?`<span class="badge badge-ok">${__('status.active')}</span>`:`<span class="badge badge-muted">${__('status.inactive')}</span>`}</td><td><button class="copy-btn" onclick="MIZBAN.editClient(${c.id},'${esc(c.name)}',${c.degree},${c.status})">✏️</button> <button class="copy-btn" onclick="MIZBAN.delClient(${c.id})">🗑</button></td></tr>`).join('');
}
$('#btnNewClient')?.addEventListener('click',()=>{$('#clientForm').reset();$('#clientForm [name=id]').value='';$('#clientForm [name=degree]').value=5;$('#clientModalTitle').textContent=__('clients.modal.new');openModal('clientModal')});
$('#clientForm')?.addEventListener('submit',async e=>{
    e.preventDefault();const f=new FormData(e.target);const id=f.get('id');
    const b={name:f.get('name'),degree:Number(f.get('degree')),status:Number(f.get('status'))};
    if(f.get('secret'))b.secret=f.get('secret');
    const d=id?await api('admin_client_update',{method:'POST',body:{id:Number(id),...b}}):await api('admin_client_create',{method:'POST',body:b});
    if(d.ok){toast(__('toast.saved'),'success');closeModal('clientModal');loadClients()}else toast(d.error,'error')
});

/* ---------- Providers ---------- */
async function loadProviders(){
    const b=$('#providersBody');b.innerHTML=`<div class="loading">${__('generic.loading')}</div>`;
    const d=await api('admin_providers');
    if(!d.providers){b.innerHTML=esc(d.error);return}
    b.innerHTML=d.providers.map(p=>`<div class="provider-card"><div class="provider-head"><div><div class="provider-name">${esc(p.name)} <span style="background:rgba(139,92,246,.15);color:#8b5cf6;font-size:10px;padding:2px 8px;border-radius:10px;margin-inline-start:6px">${__('dash.priority')}: ${p.priority||100}</span></div><div class="provider-meta">slug: <code>${esc(p.slug)}</code> · type: <code>${esc(p.type)}</code></div></div><div class="provider-actions"><label class="switch"><input type="checkbox" ${p.status==1?'checked':''} onchange="MIZBAN.toggleProvider(${p.id},this.checked)"><span class="slider"></span></label><button class="copy-btn" onclick="MIZBAN.editProvider(${p.id})">✏️</button><button class="copy-btn" onclick="MIZBAN.delProvider(${p.id})">🗑</button></div></div><div class="provider-url"><strong>${__('providers.urlLabel')}</strong> <code>${esc(p.baseurl)}</code></div></div>`).join('');
}
$('#btnNewProvider')?.addEventListener('click',()=>{$('#providerForm').reset();$('#providerForm [name=id]').value='';$('#providerForm [name=type]').value='chat';$('#providerForm [name=priority]').value=100;$('#providerModalTitle').textContent=__('providers.modal.new');openModal('providerModal')});
$('#providerForm')?.addEventListener('submit',async e=>{
    e.preventDefault();const f=new FormData(e.target);
    const b={name:f.get('name'),slug:f.get('slug'),baseurl:f.get('baseurl'),type:f.get('type'),priority:Number(f.get('priority'))||100,status:Number(f.get('status'))};
    const id=f.get('id');
    const d=id?await api('admin_provider_update',{method:'POST',body:{id:Number(id),...b}}):await api('admin_provider_create',{method:'POST',body:b});
    if(d.ok){toast(__('toast.saved'),'success');closeModal('providerModal');loadProviders()}else toast(d.error,'error')
});

/* ---------- Keys ---------- */
async function loadKeys(){
    const sel=$('#keyProviderFilter');
    if(!sel.dataset.init){sel.innerHTML=`<option value="">${__('generic.all')}</option>`+M.providers.map(p=>`<option value="${p.id}">${esc(p.name)}</option>`).join('');sel.dataset.init='1';sel.addEventListener('change',()=>{keyProviderFilter=sel.value;loadKeys()})}
    const b=$('#keysBody');b.innerHTML=`<div class="loading">${__('generic.loading')}</div>`;
    const ps=keyProviderFilter?[{id:keyProviderFilter,name:M.providers.find(p=>p.id==keyProviderFilter)?.name||''}]:M.providers;
    let html='';
    for(const p of ps){
        const d=await api('admin_keys',{qs:{provider_id:p.id}});
        if(!d.keys)continue;
        html+=`<div class="key-group"><h4>${esc(p.name)}</h4>`;
        if(!d.keys.length)html+=`<p class="muted">${__('keys.noneAdd')} <button class="copy-btn" onclick="MIZBAN.newKey(${p.id})">➕ ${__('action.add')}</button></p>`;
        else{html+=`<div class="table-wrap"><table class="table"><thead><tr><th>${__('field.label')}</th><th>${__('field.status')}</th><th>${__('key.col.requests')}</th><th>${__('key.col.errors')}</th><th>${__('key.col.lastUsed')}</th><th>${__('key.col.cooldown')}</th><th>${__('req.col.actions')}</th></tr></thead><tbody>`;
        d.keys.forEach(k=>{html+=`<tr><td>${esc(k.label)}</td><td>${k.status==1?(k.cooldown_until&&k.cooldown_until>new Date().toISOString()?`<span class="badge badge-warn">${__('status.cooldown')}</span>`:`<span class="badge badge-ok">${__('status.active')}</span>`):`<span class="badge badge-muted">${__('status.inactive')}</span>`}</td><td>${k.request_count}</td><td>${k.error_count}</td><td class="text-xs muted">${fmt(k.last_used_at)}</td><td class="text-xs">${k.cooldown_until?fmt(k.cooldown_until):'-'}</td><td><button class="copy-btn" onclick="MIZBAN.editKey(${k.id},${p.id},'${esc(k.label)}',${k.status})">✏️</button> <button class="copy-btn" onclick="MIZBAN.newKey(${p.id})">➕</button> <button class="copy-btn" onclick="MIZBAN.delKey(${k.id})">🗑</button></td></tr>`});
        html+='</tbody></table></div>'}
        html+='</div>';
    }
    b.innerHTML=html||`<p class="muted">${__('generic.noProvider')}</p>`;
}
$('#keyForm')?.addEventListener('submit',async e=>{
    e.preventDefault();const f=new FormData(e.target);
    const id=Number(f.get('id'))||0;
    const b={provider_id:Number(f.get('provider_id')),label:f.get('label'),status:Number(f.get('status'))};
    if(f.get('key_value'))b.key_value=f.get('key_value');
    if(f.get('reset_stats'))b.reset_stats=true;
    // کلید جدید → admin_key_create؛ ویرایش → admin_key_update
    const d=id?await api('admin_key_update',{method:'POST',body:{id,...b}}):await api('admin_key_create',{method:'POST',body:b});
    if(d.ok){toast(__('toast.saved'),'success');closeModal('keyModal');loadKeys()}else toast(d.error,'error')
});

/* ---------- Models ---------- */
async function loadModels(){
    const b=$('#modelsBody');b.innerHTML=`<div class="loading">${__('generic.loading')}</div>`;
    const d=await api('admin_models');
    if(!d.models){b.innerHTML=esc(d.error);return}
    b.innerHTML=d.models.map(m=>`<div class="model-card"><div class="model-head"><div><div class="model-name">${esc(m.display_name)}</div><div class="model-meta"><code>${esc(m.model_id)}</code> · ${esc(m.provider_name||'-')}${m.fallback_model?` · fallback: <code>${esc(m.fallback_model)}</code>`:''}</div></div><div class="model-actions"><label class="switch"><input type="checkbox" ${m.status==1?'checked':''} onchange="MIZBAN.toggleModel(${m.id},this.checked)"><span class="slider"></span></label><button class="copy-btn" onclick="MIZBAN.editModel(${m.id})">✏️</button><button class="copy-btn" onclick="MIZBAN.delModel(${m.id})">🗑</button></div></div></div>`).join('');
}
$('#btnNewModel')?.addEventListener('click',()=>{
    const sel=$('#modelProviderSelect');sel.innerHTML=M.providers.map(p=>`<option value="${p.id}">${esc(p.name)}</option>`).join('');
    $('#modelForm').reset();$('#modelForm [name=id]').value='';$('#modelForm [name=status]').value=1;$('#modelModalTitle').textContent=__('models.modal.new');openModal('modelModal');
});
$('#modelForm')?.addEventListener('submit',async e=>{
    e.preventDefault();const f=new FormData(e.target);
    const b={provider_id:Number(f.get('provider_id')),model_id:f.get('model_id'),display_name:f.get('display_name'),fallback_model:f.get('fallback_model'),status:Number(f.get('status'))};
    const id=f.get('id');
    const d=id?await api('admin_model_update',{method:'POST',body:{id:Number(id),...b}}):await api('admin_model_create',{method:'POST',body:b});
    if(d.ok){toast(__('toast.saved'),'success');closeModal('modelModal');loadModels()}else toast(d.error,'error')
});

/* ---------- Logs ---------- */
async function loadLogs(){
    const b=$('#logList');b.innerHTML=`<div class="loading">${__('generic.loading')}</div>`;
    const d=await api('admin_logs',{qs:{limit:200,level:logFilter}});
    if(!d.logs){b.innerHTML=esc(d.error);return}
    b.innerHTML=d.logs.map(l=>`<div class="log-entry"><span class="log-time">${fmt(l.created_at)}</span><span class="log-level ${l.level}">${esc(String(l.level).toUpperCase())}</span><span class="log-msg">${esc(l.message)}${l.context?`<div class="log-ctx">${esc(l.context)}</div>`:''}</span></div>`).join('');
}
$('#logFilter')?.addEventListener('change',e=>{logFilter=e.target.value;loadLogs()});

/* ---------- Test ---------- */
$('#testMode')?.addEventListener('change',e=>{const mf=$('#modelField');if(e.target.value==='general'){mf.style.display='none'}else{mf.style.display='block'}});
$('#testForm')?.addEventListener('submit',async e=>{
    e.preventDefault();const f=new FormData(e.target);
    const mode=f.get('mode')||'specific',model=f.get('model')||'',action=f.get('action'),input=f.get('input'),code=f.get('code'),secret=f.get('secret');
    const box=$('#testResult');box.innerHTML=`<span class="spin">⏳</span> ${__('test.sending')}`;
    const payload={input};if(action==='chat')payload.messages=[{role:'user',content:input}];
    const body={mode,action,payload};if(mode==='specific'&&model)body.model=model;
    const res=await fetch(`${API}?action=send&lang=${M.lang}`,{method:'POST',headers:{'Content-Type':'application/json','X-Client-Code':code,'X-Client-Secret':secret},body:JSON.stringify(body)});
    const d=await res.json();
    if(d.ok&&d.request_id){
        box.innerHTML=`<div class="alert alert-info">${__('test.sent',{id:d.request_id,mode})}</div>`;
        let t=0;const poll=async()=>{t++;const rd=await api('result',{qs:{id:d.request_id}});
            if(rd.status==='completed'){const fb=rd.fallback_used?__('test.fallback',{f:rd.fallback_used}):'';box.innerHTML=`<div class="alert" style="background:rgba(16,185,129,.1);color:#10b981">${__('test.completed',{provider:esc(rd.provider||'-'),fallback:fb})}</div><pre class="code" style="max-height:400px;overflow:auto">${esc(typeof rd.response==='string'?rd.response:JSON.stringify(rd.response,null,2))}</pre>`}
            else if(rd.status==='failed'){box.innerHTML=`<div class="alert alert-warn">${__('test.failed',{error:esc(rd.error||'')})}</div>`}
            else if(t<30)setTimeout(poll,1000);else box.innerHTML=`<div class="alert alert-warn">${__('test.timeout')}</div>`};
        setTimeout(poll,800);
    }else{box.innerHTML=`<div class="alert alert-warn">${__('login.failedShort')}</div><pre class="code">${esc(JSON.stringify(d,null,2))}</pre>`}
});
$('#btnCopyResult')?.addEventListener('click',()=>copy($('#testResult').innerText));

/* ---------- Modal helpers ---------- */
function openModal(id){$('#'+id).classList.add('active')}
function closeModal(id){$('#'+id).classList.remove('active')}
$$('.modal-close').forEach(b=>b.addEventListener('click',()=>closeModal(b.dataset.close)));
$$('.modal').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('active')}));
$$('.nav-item').forEach(n=>n.addEventListener('click',e=>{e.preventDefault();if(!M.loggedIn){toast(__('toast.loginFirst'),'error');return}switchView(n.dataset.view)}));
$('#menuToggle')?.addEventListener('click',()=>$('#sidebar')?.classList.toggle('open'));

/* ---------- Global helpers (inline onclick) ---------- */
Object.assign(window.MIZBAN,{
    viewReq:async id=>{
        const d=await api('admin_requests',{qs:{page:1,per_page:1000}});const r=(d.requests||[]).find(x=>x.id==id);if(!r)return;
        let pl='';try{pl=JSON.stringify(JSON.parse(r.payload||'{}'),null,2)}catch(e){pl=r.payload}
        $('#detailModalBody').innerHTML=`<div class="field"><label>${__('detail.model')}</label><div><code>${modelDisplay(r.model)}</code></div></div>
            <div class="field"><label>${__('detail.provider')}</label><div>${esc(r.provider_slug||'-')}</div></div>
            <div class="field"><label>${__('detail.fallback')}</label><div>${esc(r.fallback_used||'-')}</div></div>
            <div class="field"><label>${__('detail.status')}</label><div>${sPill(r.status)}</div></div>
            <div class="field"><label>${__('detail.payload')}</label><pre class="code">${esc(pl)}</pre></div>
            ${r.response?`<div class="field"><label>${__('detail.response')}</label><pre class="code" style="max-height:300px;overflow:auto">${esc(String(r.response).substring(0,5000))}</pre></div>`:''}
            ${r.error?`<div class="alert alert-warn">${esc(r.error)}</div>`:''}`;
        openModal('detailModal');
    },
    reproc:async id=>{const d=await api('admin_reprocess',{method:'POST',body:{id}});if(d.ok){toast(__('toast.queued'),'success');loadReqs()}else toast(d.error,'error')},
    editClient:(id,name,deg,st)=>{const f=$('#clientForm');f.id.value=id;f.name.value=name;f.degree.value=deg;f.status.value=st;f.secret.value='';f.secret.placeholder=__('action.keep');$('#clientModalTitle').textContent=__('clients.modal.edit');openModal('clientModal')},
    delClient:async id=>{if(!confirm(__('confirm.deleteClient')))return;const d=await api('admin_client_delete',{method:'POST',body:{id}});if(d.ok){toast(__('toast.deleted'),'success');loadClients()}else toast(d.error,'error')},
    toggleProvider:async(id,on)=>{await api('admin_provider_update',{method:'POST',body:{id,status:on?1:0}});toast(on?__('status.on'):__('status.off'))},
    editProvider:async id=>{const d=await api('admin_providers');const p=(d.providers||[]).find(x=>x.id==id);if(!p)return;const f=$('#providerForm');f.id.value=p.id;f.name.value=p.name;f.slug.value=p.slug;f.baseurl.value=p.baseurl;f.type.value=p.type;f.priority.value=p.priority||100;f.status.value=p.status;$('#providerModalTitle').textContent=__('providers.modal.edit');openModal('providerModal')},
    delProvider:async id=>{if(!confirm(__('confirm.deleteProvider')))return;const d=await api('admin_provider_delete',{method:'POST',body:{id}});if(d.ok){toast(__('toast.deleted'),'success');loadProviders()}else toast(d.error,'error')},
    newKey:pid=>{const f=$('#keyForm');f.reset();f.id.value='';f.provider_id.value=pid;f.status.value=1;$('#keyModalTitle').textContent=__('keys.modal.new');openModal('keyModal')},
    editKey:(id,pid,label,st)=>{const f=$('#keyForm');f.reset();f.id.value=id;f.provider_id.value=pid;f.label.value=label;f.status.value=st;f.key_value.value='';f.key_value.placeholder=__('action.keep');$('#keyModalTitle').textContent=__('keys.modal.edit');openModal('keyModal')},
    delKey:async id=>{if(!confirm(__('confirm.deleteKey')))return;const d=await api('admin_key_delete',{method:'POST',body:{id}});if(d.ok){toast(__('toast.deleted'),'success');loadKeys()}else toast(d.error,'error')},
    toggleModel:async(id,on)=>{await api('admin_model_update',{method:'POST',body:{id,status:on?1:0}});toast(on?__('status.on'):__('status.off'))},
    editModel:async id=>{const d=await api('admin_models');const m=(d.models||[]).find(x=>x.id==id);if(!m)return;const f=$('#modelForm');f.id.value=m.id;f.provider_id.value=m.provider_id;f.model_id.value=m.model_id;f.display_name.value=m.display_name;f.fallback_model.value=m.fallback_model||'';f.status.value=m.status;$('#modelModalTitle').textContent=__('models.modal.edit');openModal('modelModal')},
    delModel:async id=>{if(!confirm(__('confirm.deleteModel')))return;const d=await api('admin_model_delete',{method:'POST',body:{id}});if(d.ok){toast(__('toast.deleted'),'success');loadModels()}else toast(d.error,'error')}
});

/* ---------- Clock / autorefresh ---------- */
setInterval(()=>{const el=$('#liveTime'); if(el) el.textContent=new Date().toLocaleTimeString(M.lang==='en'?'en-US':'fa-IR')},1000);
setInterval(()=>{if(curView==='dashboard'&&M.loggedIn)loadDash()},5000);
checkSession();
})();
