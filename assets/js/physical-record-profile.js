/* Shared physical-copy profile. All document names and paths use text nodes. */
(() => {
  'use strict';
  const modal=document.getElementById('physicalRecordProfile'); if(!modal) return;
  const el=id=>document.getElementById('vcp'+id), isCabinet=modal.dataset.cabinet==='1';
  let profile=null, currentId=null, action='', busy=false, dirty=false, changed=false, requestId=0;
  const node=(tag,text,cls='')=>{const n=document.createElement(tag);n.textContent=text;n.className=cls;return n;};
  const message=(text='',kind='error')=>{el('Message').textContent=text;el('Message').dataset.kind=kind;el('Message').hidden=!text;};
  async function api(url,options={}) {
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),20000);
    try {
      const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options,signal:controller.signal});
      if(response.redirected) throw new Error('Your session may have expired. Refresh and sign in again.');
      let body;try{body=await response.json();}catch(invalidResponse){throw new Error('The server returned an unexpected response. Refresh and sign in again; if it continues, ask the administrator to check the server log.');}if(!response.ok || !body.ok) throw new Error(body.message || 'Unable to process physical record.');return body;
    } catch(error) {if(error.name==='AbortError' || error instanceof TypeError) throw new Error(options.method==='POST'?'The response was interrupted; the change may already be saved. Close and reopen this profile before retrying.':'Unable to connect. Close and reopen this profile to retry.');throw error;}
    finally{clearTimeout(timer);}
  }
  function history(target,rows,kind) {
    target.replaceChildren();if(!rows.length){target.append(node('p','No entries yet.','vcp-help'));return;}
    for(const row of rows){const item=node('div','','vcp-history-row');
      if(kind==='borrow') {item.append(node('strong',row.action_type+' · '+row.current_holder_name),node('small',row.action_date+' · '+(row.recorded_by||'Unavailable actor')),node('div',row.remarks||''));}
      else item.append(node('strong',row.previous_path+' → '+row.new_path),node('small',row.moved_at+' · '+(row.moved_by_name||'Unavailable actor')),node('div',row.reason||''));
      target.append(item);
    }
  }
  function render() {
    const d=profile.document;
    el('DigitalNotice').hidden=!d.digital_destroyed;
    el('Heading').textContent=d.file_name;el('Number').textContent=d.record_number||'No record number';el('Category').textContent=d.category;
    el('State').textContent=d.filing_state;el('Custody').textContent=d.physical_status||'Not registered';el('Sync').textContent=d.sync_status;
    el('Versions').textContent='v'+d.current_version+' / '+(d.location_id?'v'+d.physical_version:'—');
    el('Path').textContent=d.full_physical_path|| (d.location_id?'Unassigned — confirm the actual location before filing.':'No physical copy is registered. This does not prove paper does not exist.');
    const latest=profile.borrow_history.find(row=>row.action_type==='Borrowed');
    const now=new Date(),today=[now.getFullYear(),String(now.getMonth()+1).padStart(2,'0'),String(now.getDate()).padStart(2,'0')].join('-');
    const overdue=d.physical_status==='Borrowed' && latest?.expected_return_date && latest.expected_return_date<today;
    el('HolderSummary').classList.toggle('vcp-overdue',Boolean(overdue));
    el('HolderSummary').textContent=d.physical_status==='Borrowed'?(latest?latest.current_holder_name+' · Expected return: '+(latest.expected_return_date||'Not set')+(overdue?' · OVERDUE':''):'Borrowed; historical holder information is unavailable.'):(d.physical_folder_id?'Not checked out.':'Location confirmation pending.');
    el('Assign').hidden=!profile.can_manage || Boolean(d.physical_folder_id) || d.physical_status==='Borrowed';
    el('Transfer').hidden=!profile.can_manage || !isCabinet || !d.physical_folder_id || !d.folder_revision || !['Stored','Returned'].includes(d.physical_status);
    el('Checkout').hidden=!profile.can_manage || !isCabinet || (!d.physical_folder_id && d.physical_status!=='Borrowed');
    el('Checkout').textContent=d.physical_status==='Borrowed'?'Record return':'Manage check-out';
    el('Replace').hidden=!profile.can_manage || !isCabinet || d.digital_destroyed || d.sync_status!=='Replacement Required';
    el('Dispose').hidden=!isCabinet || !d.physical_disposal_eligible;
    el('Checkout').disabled=d.sync_status==='Replacement Required';
    el('Cabinet').hidden=isCabinet;el('Cabinet').href='virtual_cabinet.php?doc='+encodeURIComponent(d.doc_id);
    const query=new URLSearchParams({parent:d.parent_category||'',type:d.category||'',doc:String(d.doc_id)});
    if(d.lifecycle_status==='Archived')query.set('view_archives','1');
    el('Digital').href=(d.record_phase==='Official'?'documents.php':'general_docs.php')+'?'+query;
    el('Digital').hidden=!isCabinet || d.digital_destroyed;
    if(d.digital_destroyed)el('Digital').removeAttribute('href');
    history(el('BorrowHistory'),profile.borrow_history,'borrow');history(el('MoveHistory'),profile.movement_history,'move');
    el('Content').hidden=false;listMode();
  }
  function listMode(){action='';dirty=false;el('Form').hidden=true;el('Actions').hidden=false;el('History').hidden=false;el('Cancel').hidden=true;el('Save').hidden=true;el('Digital').hidden=!isCabinet || !profile || profile.document.digital_destroyed;}
  const usesFolder=()=>action==='assign_copy' || action==='transfer_copy';
  const selectedFolder=()=>profile?.folders.find(f=>'folder:'+f.id===el('Folder').value);
  function saveState(){el('Save').disabled=busy || (usesFolder() && !selectedFolder());}
  function folderPreview(){const folder=selectedFolder();el('DestinationPreview').hidden=action!=='transfer_copy';el('DestinationPath').textContent=folder?folder.path+' ['+folder.code+']':'Select the destination folder below the current storage location.';saveState();}
  function folderOptions(){const selected=el('Folder').value,query=el('FolderSearch').value.trim().toLocaleLowerCase();el('Folder').replaceChildren(new Option('Select physical folder',''));
    const eligible=profile.folders.filter(f=>action!=='transfer_copy' || String(f.id)!==String(profile.document.physical_folder_id));
    const choices=eligible.filter(f=>(f.path+' '+f.code).toLocaleLowerCase().includes(query));
    for(const f of choices)el('Folder').add(new Option(f.path+' ['+f.code+']','folder:'+f.id));
    if(choices.some(f=>'folder:'+f.id===selected))el('Folder').value=selected;
    if(el('Folder').value!==selected)el('Confirmed').checked=false;
    el('Folder').disabled=choices.length===0;
    el('FolderEmpty').hidden=choices.length>0;
    el('FolderEmpty').textContent=eligible.length===0?(action==='transfer_copy'?'No other active physical folder is available. Create a destination folder through Manage locations, then reopen this profile.':'No active physical folders are available. Create a physical folder through Manage locations, then reopen this profile. Digital record folders do not appear here.'):'No physical folders match your search. Clear or change the search text.';
    folderPreview();
  }
  function edit(next) {
    message();action=next;el('Form').reset();el('Form').hidden=false;el('Actions').hidden=true;el('History').hidden=true;el('Cancel').hidden=false;el('Save').hidden=false;el('Digital').hidden=true;
    el('FolderFields').hidden=!usesFolder();el('Folder').required=usesFolder();el('Folder').disabled=!usesFolder();
    el('BorrowFields').hidden=next!=='borrow_copy';el('Holder').required=next==='borrow_copy';el('Save').disabled=false;
    el('DisposalFields').hidden=next!=='dispose_physical_copy';el('DisposalMethod').required=next==='dispose_physical_copy';el('TypedConfirmation').required=next==='dispose_physical_copy';
    const texts={assign_copy:['File physical copy','I verified that the current digital version has a matching physical copy and placed it in the selected folder.'],transfer_copy:['Transfer physical copy','I confirm that I physically moved the same paper copy from its displayed storage location into the selected destination. This is not a version replacement.'],borrow_copy:['Record check-out','I confirm that the physical copy was handed to the selected holder.'],return_copy:['Record return','I confirm that the physical copy was received back. If its location is unassigned, I will confirm its folder next.'],replace_physical_copy:['Replace physical copy','I printed/verified the current version, replaced the stored copy and segregated the old copy as superseded.'],dispose_physical_copy:['Dispose physical copy','I confirm that the real paper copy has been physically destroyed using the selected method. Remove its active cabinet registration and preserve the disposal evidence.']};
    el('FormTitle').textContent=texts[next][0];el('Confirmation').textContent=texts[next][1];
    if(next==='assign_copy' && profile.document.digital_destroyed)el('Confirmation').textContent='I verified the existing registered paper copy and placed it in the selected physical folder. The digital file remains destroyed and this does not replace the recorded paper version.';
    if(usesFolder())folderOptions();
    if(next==='borrow_copy'){el('Holder').replaceChildren(new Option('Select current holder',''));for(const holder of profile.holders)el('Holder').add(new Option(holder.full_name,String(holder.user_id)));}
    dirty=false;el(usesFolder()?'FolderSearch':next==='borrow_copy'?'Holder':next==='dispose_physical_copy'?'DisposalMethod':'Reason').focus();
  }
  async function load(id){const serial=++requestId;profile=null;currentId=id;message();el('Content').hidden=true;el('Digital').hidden=true;el('Save').hidden=true;el('Cancel').hidden=true;el('Heading').textContent='Loading record…';
    try{const result=await api('actions/cabinet_fetcher.php?'+new URLSearchParams({action:'get_document_profile',doc_id:String(id)}));if(serial!==requestId)return;profile=result;render();}
    catch(error){if(serial!==requestId)return;el('Heading').textContent='Physical record';message(error.message);}
  }
  window.openPhysicalRecordProfile=id=>{if(busy)return;changed=false;dirty=false;bootstrap.Modal.getOrCreateInstance(modal).show();load(id);};
  el('Assign').addEventListener('click',()=>edit('assign_copy'));el('Checkout').addEventListener('click',()=>edit(profile.document.physical_status==='Borrowed'?'return_copy':'borrow_copy'));el('Replace').addEventListener('click',()=>edit('replace_physical_copy'));
  el('Transfer').addEventListener('click',()=>edit('transfer_copy'));
  el('Dispose').addEventListener('click',()=>edit('dispose_physical_copy'));
  el('FolderSearch').addEventListener('input',folderOptions);
  el('Folder').addEventListener('change',()=>{el('Confirmed').checked=false;folderPreview();});
  el('Cancel').addEventListener('click',()=>{if(busy)return;if(!dirty || window.confirm('Discard unsaved physical-copy details?')){message();listMode();}});
  el('Form').addEventListener('input',()=>dirty=true);el('Form').addEventListener('change',()=>dirty=true);
  el('Form').addEventListener('submit',async event=>{
    event.preventDefault();if(busy || !profile || !action)return;busy=true;el('Save').disabled=true;message();
    const data=new FormData(el('Form'));data.set('action',action);data.set('doc_id',String(profile.document.doc_id));data.set('revision',profile.document.revision);
    if(action==='transfer_copy'){data.set('source_revision',profile.document.folder_revision);data.set('destination_revision',selectedFolder()?.revision||'');}
    if(action==='dispose_physical_copy')data.set('source_revision',profile.document.folder_revision);
    try{const result=await api('actions/physical_location_handler.php',{method:'POST',body:data});changed=true;dirty=false;if(result.removed){requestId++;profile=null;action='';el('Heading').textContent='Physical copy disposed';el('Content').hidden=true;el('Save').hidden=true;el('Cancel').hidden=true;el('Digital').hidden=true;message(result.message,'success');}else{await load(currentId);message(result.message,'success');}document.dispatchEvent(new CustomEvent('physical-copy-updated'));}
    catch(error){message(error.message);}
    finally{busy=false;saveState();}
  });
  modal.addEventListener('hide.bs.modal',event=>{if(busy || (dirty && !window.confirm('Discard unsaved physical-copy details?')))event.preventDefault();});
  modal.addEventListener('hidden.bs.modal',()=>{requestId++;profile=null;listMode();if(changed && !isCabinet)window.location.reload();});
})();
