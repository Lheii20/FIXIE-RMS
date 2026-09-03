(() => {
  'use strict';
  const root=document.getElementById('vc3Workspace');if(!root)return;
  // data-redesign="vc5c": install this four-column renderer with the matching page.
  if(root.dataset.redesign!=='vc5c'){
    const message=document.getElementById('vc3Error');
    if(message){message.hidden=false;message.textContent='Virtual Cabinet files are out of sync. Save the updated page and script together, then refresh.';}
    return;
  }
  const el=id=>document.getElementById('vc3'+id);
  const node=(tag,text,cls='')=>{const n=document.createElement(tag);n.textContent=text;n.className=cls;return n;};
  let scope='all',custody='all',page=1,pages=1,query='',serial=0,timer=null,nodes=[],copyStats={},directorySerial=0;
  const expanded=new Map();
  const icon=name=>{const i=node('i','','fas fa-'+name);i.setAttribute('aria-hidden','true');return i;};
  const typeIcons={building:'building',room:'door-open',cabinet:'archive',drawer:'layer-group',box:'box',folder:'folder'};
  function error(text=''){el('Error').textContent=text;el('Error').hidden=!text;}
  function syncExport(){const form=el('ExportForm');if(!form)return;el('ExportScope').value=scope;el('ExportCustody').value=custody;el('ExportQuery').value=query;}
  async function api(params){const controller=new AbortController(), timeout=setTimeout(()=>controller.abort(),20000);
    try{const response=await fetch('actions/cabinet_fetcher.php?'+new URLSearchParams(params),{credentials:'same-origin',cache:'no-store',signal:controller.signal});
      if(response.redirected)throw new Error('Refresh and sign in again.');let body;try{body=await response.json();}catch(invalidResponse){throw new Error('Unexpected server response. Refresh and sign in again; ask the administrator to check the server log if this continues.');}if(!response.ok || !body.ok)throw new Error(body.message||'Unable to load cabinet.');return body;
    }catch(e){if(e.name==='AbortError' || e instanceof TypeError)throw new Error('Connection interrupted. Use Refresh to try again.');throw e;}finally{clearTimeout(timeout);}}
  function tree(){
    const target=el('Tree'),filter=(el('LocationSearch')?.value||'').trim().toLocaleLowerCase();target.replaceChildren();
    const choice=(text,key,parent,count,kind='folder')=>{
      const button=node('button','');button.type='button';button.title=text;button.dataset.scope=key;
      button.setAttribute('aria-current',scope===key?'true':'false');
      const symbol=icon(kind);symbol.classList.add('vc5-tree-icon');button.append(symbol,node('span',text,'vc5-tree-label'));
      if(count!==undefined)button.append(node('span',String(count),'vc5-tree-count'));
      button.addEventListener('click',()=>{scope=key;page=1;clearTimeout(timer);load();target.querySelectorAll('button[data-scope]').forEach(b=>b.setAttribute('aria-current',b.dataset.scope===key?'true':'false'));});
      parent.append(button);
    };
    choice('All physical copies','all',target,copyStats.total,'layer-group');
    choice('Unassigned locations','unassigned',target,copyStats.unassigned,'inbox');
    target.append(node('div','Physical directory','vc5-tree-divider'));
    const visible=new Set(),byKey=new Map(nodes.map(n=>[n.key,n])),byParent=new Map();
    for(const n of nodes){
      if(!byParent.has(n.parent))byParent.set(n.parent,[]);byParent.get(n.parent).push(n);
      if(!filter || (n.path+' '+n.code+' '+n.name).toLocaleLowerCase().includes(filter)){
        let current=n;const visited=new Set();
        while(current && !visited.has(current.key)){visited.add(current.key);visible.add(current.key);current=byKey.get(current.parent);}
      }
    }
    const append=(key,parent)=>{
      for(const n of byParent.get(key)||[]){
        if(!visible.has(n.key))continue;
        if(n.type==='folder'){choice(n.name+(n.available?'':' · Inactive'),n.key,parent,n.count);continue;}
        const details=node('details','');details.dataset.location=n.key;details.open=filter?true:(expanded.get(n.key)??true);
        const summary=node('summary','');summary.title=n.path;
        const chevron=icon('chevron-right');chevron.classList.add('vc5-tree-chevron');
        const symbol=icon(typeIcons[n.type]||'folder');symbol.classList.add('vc5-tree-icon');
        summary.append(chevron,symbol,node('span',n.name+(n.available?'':' · Inactive'),'vc5-tree-label'));details.append(summary);
        details.addEventListener('toggle',()=>{if(!(el('LocationSearch')?.value||'').trim())expanded.set(n.key,details.open);});
        parent.append(details);append(n.key,details);
      }
    };
    append('',target);
    if(!nodes.length)target.append(node('p',document.querySelector('[data-bs-target="#storageLocationManager"]')?'No locations yet. Use Manage locations to add your first office, room and folder.':'No physical locations have been set up yet. Contact your records custodian.','vc3-empty'));
    else if(!visible.size)target.append(node('p','No locations match. Clear or change your location search.','vc3-empty'));
  }
  async function directory(){
    const current=++directorySerial;
    try{
      const data=await api({action:'directory'});if(current!==directorySerial)return false;nodes=data.nodes;copyStats=data.stats;
      if(scope.startsWith('folder:') && !nodes.some(n=>n.key===scope))scope='all';
      tree();
      for(const [key,value] of Object.entries(data.stats)){document.querySelectorAll('[data-copy-stat="'+key+'"]').forEach(target=>target.textContent=String(value));}
      // Summary totals are global; avoid showing global counts as though they describe a selected folder.
      const labels={all:'All custody',borrowed:'Borrowed',overdue:'Overdue',due_soon:'Due in 3 days',no_due_date:'No return date'};
      for(const option of el('Custody').options)option.textContent=labels[option.value]||option.textContent;
      for(const type of ['cabinet','drawer','folder']){document.querySelectorAll('[data-location-stat="'+type+'"]').forEach(target=>target.textContent=String(nodes.filter(n=>n.type===type).length));}
      return true;
    }catch(e){if(current!==directorySerial)return false;error(e.message);el('Tree').replaceChildren(node('p','Locations could not be loaded. Use Refresh to try again.','vc3-empty'));return false;}
  }
  function tableMessage(title,description='',symbol='inbox'){
    const row=node('tr',''),cell=node('td','','vc5-empty');cell.colSpan=4;
    cell.append(icon(symbol),node('strong',title));if(description)cell.append(node('p',description));row.append(cell);el('Rows').replaceChildren(row);
  }
  async function load(){
    const current=++serial;syncExport();error();el('Prev').disabled=true;el('Next').disabled=true;
    el('Clear').disabled=!query;el('List').setAttribute('aria-busy','true');
    tableMessage('Loading physical copies…','Your selected location and custody filter are being applied.','hourglass-half');el('Count').textContent='Loading…';el('Page').textContent='—';
    const selected=nodes.find(n=>n.key===scope);
    el('Title').textContent=scope==='all'?'All physical copies':scope==='unassigned'?'Unassigned locations':selected?.name||'Physical folder';
    el('Title').title=el('Title').textContent;
    const custodyLabel={all:'',borrowed:' · Borrowed',overdue:' · Overdue',due_soon:' · Due in 3 days',no_due_date:' · No return date'};
    el('Path').textContent=(scope==='all'?'Across all storage locations':scope==='unassigned'?'Registered copies awaiting location confirmation':selected?.path||'Physical folder')+(custodyLabel[custody]||'');
    el('Path').title=el('Path').textContent;
    try{
      const result=await api({action:'get_documents',scope,custody,query,page:String(page)});if(current!==serial)return;
      page=result.page;pages=result.pages;el('Rows').replaceChildren();
      for(const record of result.data){
        const row=node('tr',''),identity=node('td',''),location=node('td',''),state=node('td',''),actions=node('td','');
        const group=node('div','','vc5-record'),symbol=node('span','','vc5-record-icon');symbol.append(icon('file-alt'));
        const text=node('div','','vc5-record-text'),name=node('strong',record.file_name);name.title=record.file_name;
        const classification=(record.lifecycle_status==='Archived'?'Archived':record.record_phase||'Working')+' · '+record.category;
        const reference=node('small',(record.record_number && !record.file_name.startsWith(record.record_number+'.')?record.record_number+' · ':'')+classification);reference.title=(record.record_number?record.record_number+' · ':'')+classification;
        text.append(name,reference);group.append(symbol,text);identity.append(group);
        const parts=(record.full_physical_path||'').split(' / '),folder=record.full_physical_path?parts.pop():'Unassigned location';
        const locationName=node('strong','','vc5-location-name');locationName.append(icon('folder'),document.createTextNode(folder));locationName.title=record.full_physical_path||folder;
        const path=node('small',parts.join(' / ')||'Location confirmation needed');path.title=record.full_physical_path||path.textContent;location.append(locationName,path);
        const positionLabel=record.is_overdue?'Overdue':record.is_due_soon?'Due soon':record.physical_status==='Borrowed'?'Borrowed':record.physical_status==='Stored'||record.physical_status==='Returned'?'In storage':'Not recorded';
        const badge=node('span',positionLabel,'vc3-position'+(record.is_overdue?' vc3-position-overdue':record.is_due_soon?' vc3-position-due':record.physical_status==='Borrowed'?' vc3-position-borrowed':''));
        state.append(badge);
        if(record.physical_status==='Borrowed'){
          const holder=node('small',record.current_holder_name||'Holder unavailable');holder.title=holder.textContent;state.append(holder,node('small','Return: '+(record.expected_return_date||'Not set')));
        }else state.append(node('small',record.filing_state==='Assigned'?'Filed in location':'Location not confirmed'));
        if(record.disposition_status==='Destroyed'){const retained=node('small','Digital destroyed · Paper retained');retained.title=retained.textContent;state.append(retained);}
        const view=node('button','','btn btn-outline-primary');view.type='button';view.title='View physical record';view.setAttribute('aria-label','View '+record.file_name);view.append(node('span','View','vc5-view-label'),icon('arrow-right'));view.addEventListener('click',()=>window.openPhysicalRecordProfile(record.doc_id));
        actions.append(view);row.append(identity,location,state,actions);el('Rows').append(row);
      }
      if(!result.data.length)tableMessage(query || custody!=='all'?'No matching physical copies':'No physical copies here',query || custody!=='all'?'Try another search or custody filter. Your selected location is unchanged.':scope==='unassigned'?'All registered copies have a confirmed location.':'Copies appear here after their physical location is confirmed.');
      const start=result.total?(page-1)*15+1:0,end=Math.min(page*15,result.total);
      el('Count').textContent=`${start}–${end} of ${result.total} copies`;el('Page').textContent=`${page} / ${pages}`;el('Prev').disabled=page<=1;el('Next').disabled=page>=pages;
    }catch(e){
      if(current!==serial)return;error(e.message);tableMessage('Unable to load physical copies','Use Refresh to try again. No record changes have been made.','exclamation-circle');el('Count').textContent='Not loaded';
    }finally{if(current===serial)el('List').setAttribute('aria-busy','false');}
  }
  el('Search').addEventListener('input',()=>{clearTimeout(timer);serial++;query=el('Search').value.trim();syncExport();el('Clear').disabled=!query;el('Prev').disabled=true;el('Next').disabled=true;page=1;timer=setTimeout(load,250);});
  el('Clear').addEventListener('click',()=>{clearTimeout(timer);el('Search').value='';query='';page=1;load();el('Search').focus();});
  el('LocationSearch').addEventListener('input',tree);
  el('Custody').addEventListener('change',()=>{clearTimeout(timer);custody=el('Custody').value;page=1;load();});
  el('Prev').addEventListener('click',()=>{if(page>1){page--;load();}});el('Next').addEventListener('click',()=>{if(page<pages){page++;load();}});
  el('Refresh').addEventListener('click',async()=>{clearTimeout(timer);el('Refresh').disabled=true;try{if(await directory())await load();}finally{el('Refresh').disabled=false;}});
  if(el('ExportForm'))el('ExportForm').addEventListener('submit',async event=>{
    event.preventDefault();
    const button=el('Export');if(button.disabled)return;
    query=el('Search').value.trim();syncExport();error();
    const label=button.querySelector('span'),original=label.textContent;
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),45000);
    button.disabled=true;button.setAttribute('aria-busy','true');label.textContent='Preparing…';
    try {
      const response=await fetch(el('ExportForm').action,{
        method:'POST',body:new FormData(el('ExportForm')),credentials:'same-origin',cache:'no-store',
        headers:{Accept:'text/csv, application/json','X-Requested-With':'XMLHttpRequest'},signal:controller.signal
      });
      if(response.redirected)throw new Error('Refresh and sign in again.');
      const contentType=response.headers.get('Content-Type')||'';
      if(!response.ok || !/^text\/csv\b/i.test(contentType)) {
        let message='The server did not return an inventory CSV. Refresh and sign in again; contact the administrator if this continues.';
        if(contentType.includes('application/json')) {
          try {
            const body=await response.json();
            message=['session_expired','force_logout'].includes(body.status)?'Your session ended. Refresh and sign in again.':body.message||message;
          }catch(invalidResponse){}
        }
        throw new Error(message);
      }
      const header=response.headers.get('Content-Disposition')||'';
      const match=header.match(/filename="?(physical_inventory_[0-9_-]+\.csv)"?(?:;|$)/i);
      if(!/^attachment\b/i.test(header) || !match)throw new Error('The inventory download response was incomplete. Try again.');
      const blob=await response.blob(),url=URL.createObjectURL(blob),link=document.createElement('a');
      link.href=url;link.download=match[1];document.body.append(link);link.click();link.remove();
      setTimeout(()=>URL.revokeObjectURL(url),1000);
    }catch(e){
      error(e.name==='AbortError' || e instanceof TypeError?'The export connection was interrupted. Try again.':e.message||'The physical inventory could not be exported.');
    }finally{
      clearTimeout(timeout);button.disabled=false;button.removeAttribute('aria-busy');label.textContent=original;
    }
  });
  document.addEventListener('physical-copy-updated',async()=>{if(await directory())await load();});
  (async()=>{
    if(!await directory()){tableMessage('Unable to load the cabinet','Use Refresh to try again.','exclamation-circle');el('Count').textContent='Not loaded';return;}
    const params=new URLSearchParams(window.location.search),folder=params.get('physical_folder'),custodyParam=params.get('custody');
    if(folder && /^\d+$/.test(folder) && nodes.some(n=>n.key==='folder:'+folder))scope='folder:'+folder;
    if(['borrowed','overdue','due_soon','no_due_date'].includes(custodyParam)){custody=custodyParam;el('Custody').value=custody;}
    tree();await load();const doc=params.get('doc');if(doc && /^\d+$/.test(doc))window.openPhysicalRecordProfile(doc);
  })();
})();
