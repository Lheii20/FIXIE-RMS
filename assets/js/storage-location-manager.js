(() => {
  'use strict';
  const modal = document.getElementById('storageLocationManager');
  if (!modal) return;
  const el = id => document.getElementById('vcm' + id);
  const types = {
    building:{label:'Office / site',parents:[],max:100},room:{label:'Room',parents:['building'],max:100},
    cabinet:{label:'Cabinet',parents:['room'],max:100},drawer:{label:'Drawer',parents:['cabinet'],max:100},
    box:{label:'Box',parents:['room','drawer'],max:120},folder:{label:'Physical folder',parents:['drawer','box'],max:120}
  };
  let nodes=[], page=1, editing=null, deleting=false, dirty=false, changed=false, busy=false, loaded=false;
  const pageSize=10;
  function message(text='', error=false) { el('Message').textContent=text; el('Message').hidden=!text; el('Message').classList.toggle('is-error',error); }
  function setBusy(value) {
    busy=value; modal.setAttribute('aria-busy',String(value));
    ['Save','Add','Refresh','Close','Cancel','Prev','Next'].forEach(id=>el(id).disabled=value);
    el('Save').textContent=value?'Saving…':deleting?'Delete empty location':'Save location';
    el('Rows').querySelectorAll('button').forEach(button=>button.disabled=value || button.dataset.blocked==='1');
  }
  async function request(data) {
    const controller=new AbortController();
    const timer=setTimeout(()=>controller.abort(),20000);
    try {
      const response=await fetch(modal.dataset.endpoint,{method:data?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},body:data,signal:controller.signal});
      if (!(response.headers.get('content-type')||'').includes('application/json')) throw new Error('Your session may have expired. Refresh the page and sign in again.');
      const result=await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'Unable to process the request.');
      return result;
    } catch(error) {
      if (error.name==='AbortError' || error instanceof TypeError) throw new Error(data?'The response was interrupted. Refresh the location list before retrying; your change may already have been saved.':'Cannot reach the server. Check the connection, then refresh the list.');
      throw error;
    } finally { clearTimeout(timer); }
  }
  function textNode(tag,text,cls='') { const node=document.createElement(tag); node.textContent=text; node.className=cls; return node; }
  function render() {
    const query=el('Search').value.trim().toLocaleLowerCase(), type=el('Type').value;
    const filtered=nodes.filter(n=>(!type || n.type===type) && [n.name,n.code,n.path,types[n.type].label].join(' ').toLocaleLowerCase().includes(query));
    const pages=Math.max(1,Math.ceil(filtered.length/pageSize)); page=Math.max(1,Math.min(page,pages));
    el('Rows').replaceChildren();
    filtered.slice((page-1)*pageSize,page*pageSize).forEach(node=>{
      const row=document.createElement('tr');
      const name=document.createElement('td'), parent=document.createElement('td'), usage=document.createElement('td'), actions=document.createElement('td');
      const nameText=textNode('span',node.name,'vcm-truncate vcm-name'); nameText.title=node.name; name.append(nameText);
      name.append(textNode('span',[types[node.type].label,node.code,!node.active?'Inactive':''].filter(Boolean).join(' · '),'vcm-truncate vcm-meta'));
      const parentNode=nodes.find(n=>n.key===node.parent);
      const path=parentNode?parentNode.path:(node.parent?'Parent unavailable':'Top-level location');
      const pathText=textNode('span',path,'vcm-truncate'); pathText.title=path; parent.append(pathText);
      usage.append(textNode('span',node.in_use?'In use':'Empty','vcm-truncate'));
      usage.append(textNode('span',`${node.children} child locations · ${node.references} links`,'vcm-meta vcm-truncate'));
      const buttons=textNode('div','','vcm-row-actions');
      const edit=textNode('button','Edit','btn btn-outline-secondary'); edit.type='button'; edit.setAttribute('aria-label','Edit '+node.name); edit.addEventListener('click',()=>openEditor(node));
      const remove=textNode('button','Delete','btn btn-outline-secondary vcm-delete'); remove.type='button'; remove.disabled=node.in_use; remove.dataset.blocked=node.in_use?'1':'0'; remove.setAttribute('aria-label','Delete '+node.name);
      remove.title=node.in_use?'Contains child locations or links; deletion is blocked.':'Delete this empty location'; remove.addEventListener('click',()=>removeNode(node));
      buttons.append(edit,remove); actions.append(buttons); row.append(name,parent,usage,actions); el('Rows').append(row);
    });
    if (!filtered.length) { const row=document.createElement('tr'),cell=textNode('td',loaded?'No matching locations. Add a location or change your search.':'Loading locations…','vcm-empty'); cell.colSpan=4; row.append(cell); el('Rows').append(row); }
    el('Count').textContent=filtered.length?`${(page-1)*pageSize+1}–${Math.min(page*pageSize,filtered.length)} of ${filtered.length} locations`:'0 locations';
    el('Page').textContent=`${page} / ${pages}`;
    el('Prev').disabled=busy || page===1; el('Next').disabled=busy || page===pages;
  }
  async function load() {
    setBusy(true);
    try { const result=await request(); nodes=result.nodes; loaded=true; render(); }
    catch(error) { message(error.message,true); }
    finally { setBusy(false); render(); }
  }
  function setupFields(selectedParent='') {
    const type=el('EditType').value, spec=types[type], extended=['box','folder'].includes(type);
    el('Name').maxLength=spec.max;
    el('ParentField').hidden=spec.parents.length===0; el('Parent').required=spec.parents.length>0;
    el('Parent').replaceChildren(new Option('Select a parent location',''));
    nodes.filter(n=>spec.parents.includes(n.type) && (n.available || n.key===selectedParent)).sort((a,b)=>a.path.localeCompare(b.path)).forEach(n=>el('Parent').add(new Option(n.path+(!n.available?' (inactive/unavailable)':''),n.key)));
    el('Parent').value=selectedParent; el('Parent').disabled=deleting || Boolean(editing?.in_use);
    el('ParentHelp').textContent=editing?.in_use?'This location is in use. Its parent cannot be changed.':spec.parents.length?'Choose the actual location. Different record types can share the same drawer.':'';
    el('CodeField').hidden=!extended; el('ActiveField').hidden=!extended; el('Code').readOnly=Boolean(editing);
    el('Active').disabled=deleting || Boolean(editing?.in_use);
  }
  function listMode() {
    editing=null; deleting=false; dirty=false; el('Editor').hidden=true; el('List').hidden=false; el('Save').hidden=true; el('Cancel').hidden=true; el('Close').hidden=false; el('Title').textContent='Manage locations';
  }
  function openEditor(node=null, remove=false) {
    if (busy || !loaded) return;
    message(); editing=node; deleting=remove; dirty=false; el('Form').reset(); el('EditType').value=node?.type || el('Type').value || 'folder'; el('EditType').disabled=Boolean(node);
    el('Name').value=node?.name || ''; el('Code').value=node?.code || ''; el('Active').value=node && !node.active?'0':'1';
    el('Reason').required=Boolean(node); el('ReasonRequired').hidden=!node;
    setupFields(node?.parent || ''); el('Name').readOnly=remove; el('Title').textContent=remove?'Delete empty location':node?'Edit location':'Add location';
    el('Save').textContent=remove?'Delete empty location':'Save location'; el('Save').classList.toggle('btn-danger',remove); el('Save').classList.toggle('btn-primary',!remove);
    if(remove) message('Only this empty storage location will be deleted. Documents will not be deleted.');
    el('List').hidden=true; el('Editor').hidden=false; el('Save').hidden=false; el('Cancel').hidden=false; el('Close').hidden=true; el('Name').focus();
  }
  function removeNode(node) {
    if (busy || node.in_use) return;
    openEditor(node,true); el('Reason').focus();
  }
  el('Form').addEventListener('submit',async event=>{
    event.preventDefault(); if (busy || !el('Form').reportValidity()) return;
    const body=new FormData(); const type=el('EditType').value;
    Object.entries({action:deleting?'delete':editing?'update':'create',type,key:editing?.key || '',revision:editing?.revision || '',name:el('Name').value,parent:types[type].parents.length?el('Parent').value:'',code:el('Code').value,active:el('Active').value,reason:el('Reason').value,csrf_token:modal.dataset.token}).forEach(([key,value])=>body.append(key,value));
    setBusy(true); message();
    try { const result=await request(body); changed=true; dirty=false; listMode(); el('Type').value=type; el('Search').value=''; page=1; message(result.message); await load(); }
    catch(error) { message(error.message,true); }
    finally { setBusy(false); render(); }
  });
  el('Form').addEventListener('input',()=>dirty=true); el('Form').addEventListener('change',()=>dirty=true);
  el('EditType').addEventListener('change',()=>setupFields());
  el('Search').addEventListener('input',()=>{page=1;render();}); el('Type').addEventListener('change',()=>{page=1;render();});
  el('Prev').addEventListener('click',()=>{page--;render();}); el('Next').addEventListener('click',()=>{page++;render();});
  el('Refresh').addEventListener('click',()=>{message();load();}); el('Add').addEventListener('click',()=>openEditor());
  el('Cancel').addEventListener('click',()=>{if (!dirty || window.confirm('Discard your unsaved changes?')) {listMode();message();el('Add').focus();}});
  modal.addEventListener('shown.bs.modal',()=>{listMode();message();load();});
  modal.addEventListener('hide.bs.modal',event=>{if (busy || (dirty && !window.confirm('Discard your unsaved changes?'))) event.preventDefault();});
  modal.addEventListener('hidden.bs.modal',()=>{if(changed) window.location.reload();});
})();
