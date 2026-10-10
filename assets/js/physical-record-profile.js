/* Shared physical-copy profile. All document names and paths use text nodes. */
(() => {
  'use strict';
  const modal=document.getElementById('physicalRecordProfile'); if(!modal) return;
  const el=id=>document.getElementById('vcp'+id), isCabinet=modal.dataset.cabinet==='1';
  const actionElement=el('ActionModal'),actionModal=bootstrap.Modal.getOrCreateInstance(actionElement);
  const actionMount=el('ActionMount');
  actionMount.append(el('ActionPanel'),el('Form'),el('ConfirmDialog'));
  let profile=null, currentId=null, action='', busy=false, dirty=false, changed=false, requestId=0, allowModalClose=false, allowActionClose=false, folderBrowseAll=false, folderResultLimit=6, optionsLoaded=false, optionsLoading=false, switchingToPreview=false, switchingToAction=false;
  const node=(tag,text,cls='')=>{const n=document.createElement(tag);n.textContent=text;n.className=cls;return n;};
  const message=(text='',kind='error')=>{const target=actionElement.classList.contains('show')?el('ActionMessage'):el('Message');target.textContent=text;target.dataset.kind=kind;target.hidden=!text;};
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
    el('Loading').hidden=true;
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
    el('Assign').hidden=!profile.can_manage || !isCabinet || Boolean(d.physical_folder_id) || d.physical_status==='Borrowed';
    el('Transfer').hidden=!profile.can_manage || !isCabinet || !d.physical_folder_id || !d.folder_revision || !['Stored','Returned'].includes(d.physical_status);
    el('Checkout').hidden=!profile.can_manage || !isCabinet || (!d.physical_folder_id && d.physical_status!=='Borrowed');
    const checkoutTitle=el('Checkout').querySelector('.vcp-action-title');
    const checkoutDescription=el('Checkout').querySelector('.vcp-action-description');
    if(checkoutTitle)checkoutTitle.textContent=d.physical_status==='Borrowed'?'Record return':'Manage check-out';
    if(checkoutDescription)checkoutDescription.textContent=d.physical_status==='Borrowed'?'Confirm that the borrowed copy was returned.':'Record a borrower and expected return date.';
    el('Replace').hidden=!profile.can_manage || !isCabinet || d.digital_destroyed || d.sync_status!=='Replacement Required';
    el('Dispose').hidden=!isCabinet || !d.physical_disposal_eligible;
    el('Checkout').disabled=d.sync_status==='Replacement Required';
    el('Manage').hidden=!isCabinet || !profile.can_manage || !['Assign','Transfer','Checkout','Replace','Dispose'].some(id=>!el(id).hidden);
    el('Cabinet').hidden=isCabinet;el('Cabinet').href='virtual_cabinet.php?doc='+encodeURIComponent(d.doc_id);
    const query=new URLSearchParams({parent:d.parent_category||'',type:d.category||'',doc:String(d.doc_id)});
    if(d.lifecycle_status==='Archived')query.set('view_archives','1');
    el('Digital').href=(d.record_phase==='Official'?'documents.php':'general_docs.php')+'?'+query;
    el('Digital').hidden=!isCabinet || d.digital_destroyed;
    el('Preview').hidden=Boolean(d.digital_destroyed);
    if(d.digital_destroyed)el('Digital').removeAttribute('href');
    history(el('BorrowHistory'),profile.borrow_history,'borrow');history(el('MoveHistory'),profile.movement_history,'move');
    el('Content').hidden=false;listMode();
  }
  function listMode(){action='';dirty=false;el('Form').hidden=true;el('ActionPanel').hidden=!isCabinet;el('Actions').hidden=!isCabinet;el('History').hidden=false;el('Cancel').hidden=true;el('Save').hidden=true;el('Digital').hidden=!isCabinet || !profile || profile.document.digital_destroyed;el('Cabinet').hidden=isCabinet || !profile;el('Preview').hidden=!profile || profile.document.digital_destroyed;el('ActionModalTitle').textContent='Choose a physical-copy action';}
  const usesFolder=()=>action==='assign_copy' || action==='transfer_copy';
  const selectedFolder=()=>profile?.folders.find(f=>'folder:'+f.id===el('Folder').value);
  function saveState(){el('Save').disabled=busy;}
  const recentFolderStorageKey='drms:recent-physical-folders';
  function folderLabels(folder){
    const parts=String(folder.path||'').split(/\s*>\s*/).map(value=>value.trim()).filter(Boolean);
    return {name:(parts.length?parts[parts.length-1]:'')||('Folder '+folder.code),parent:parts.slice(0,-1).join(' › ')||'Top-level physical location'};
  }
  function readRecentFolderIds(){
    try{const value=JSON.parse(localStorage.getItem(recentFolderStorageKey)||'[]');return Array.isArray(value)?value.map(String).slice(0,6):[];}catch(error){return [];}
  }
  function rememberFolder(folder){
    try{const ids=readRecentFolderIds().filter(id=>id!==String(folder.id));ids.unshift(String(folder.id));localStorage.setItem(recentFolderStorageKey,JSON.stringify(ids.slice(0,6)));}catch(error){}
  }
  function contextFolderSuggestions(folders){
    const ignored=new Set(['official','record','records','document','documents','file','files','form','forms','company']);
    const source=((profile?.document?.category||'')+' '+(profile?.document?.parent_category||'')).toLocaleLowerCase();
    const tokens=(source.match(/[a-z0-9]+/g)||[]).filter(token=>token.length>=3&&!ignored.has(token));
    if(!tokens.length)return [];
    return folders.map(folder=>{
      const labels=folderLabels(folder),leaf=labels.name.toLocaleLowerCase(),all=(folder.path+' '+folder.code).toLocaleLowerCase();
      const score=tokens.reduce((total,token)=>total+(leaf.includes(token)?4:all.includes(token)?1:0),0);
      return {folder,score,leaf};
    }).filter(item=>item.score>0).sort((a,b)=>b.score-a.score||a.leaf.localeCompare(b.leaf)).map(item=>item.folder);
  }
  function folderSearchScore(folder,query){
    const labels=folderLabels(folder),leaf=labels.name.toLocaleLowerCase(),code=String(folder.code||'').toLocaleLowerCase(),parent=labels.parent.toLocaleLowerCase();
    if(leaf===query||code===query)return 0;
    if(leaf.startsWith(query)||code.startsWith(query))return 1;
    if(leaf.includes(query)||code.includes(query))return 2;
    if(parent.includes(query))return 3;
    return 9;
  }
  function folderPreview(){
    const folder=selectedFolder();
    el('DestinationPreview').hidden=!folder;
    if(folder){
      const labels=folderLabels(folder);
      el('DestinationPath').textContent=labels.name+' ['+folder.code+'] · '+labels.parent;
      el('DestinationHelp').textContent=action==='transfer_copy'?'This will record a physical move to the selected folder. The digital file will not be changed.':'This folder will be recorded as the actual location of the paper copy.';
    }
    saveState();
  }
  function chooseFolder(value){
    el('Folder').value=value;dirty=true;el('Confirmed').checked=false;
    const folder=selectedFolder();if(folder)rememberFolder(folder);
    clearFieldValidation(el('Folder'));clearFieldValidation(el('Confirmed'));
    folderOptions();
  }
  function renderFolderResult(folder,selectedValue){
    const value='folder:'+folder.id,labels=folderLabels(folder);
    const result=node('button','','vcp-folder-result');result.type='button';result.dataset.value=value;result.setAttribute('role','option');result.setAttribute('aria-selected',String(value===selectedValue));result.title=folder.path+' ['+folder.code+']';
    const iconWrap=node('span','','vcp-folder-result-icon');iconWrap.append(node('i','',value===selectedValue?'fas fa-circle-check':'fas fa-folder'));
    const copy=node('span','','vcp-folder-result-copy');
    const title=node('span','','vcp-folder-result-title');title.append(node('strong',labels.name),node('em',folder.code));
    copy.append(title,node('small',labels.parent));
    result.append(iconWrap,copy,node('i','','fas fa-chevron-right vcp-folder-result-arrow'));
    result.addEventListener('click',()=>chooseFolder(value));return result;
  }
  function folderOptions(){
    const selected=el('Folder').value,rawQuery=el('FolderSearch').value.trim(),query=rawQuery.toLocaleLowerCase();
    el('FolderSearchStatus').classList.remove('drms-skeleton-inline');
    const eligible=profile.folders.filter(folder=>action!=='transfer_copy'||String(folder.id)!==String(profile.document.physical_folder_id));

    el('Folder').replaceChildren(new Option('Select physical folder',''));
    for(const folder of eligible)el('Folder').add(new Option(folder.path+' ['+folder.code+']','folder:'+folder.id));
    if(eligible.some(folder=>'folder:'+folder.id===selected))el('Folder').value=selected;
    if(el('Folder').value!==selected){el('Confirmed').checked=false;clearFieldValidation(el('Confirmed'));}
    el('Folder').disabled=eligible.length===0;

    let choices=[],mode='suggested';
    if(query){
      mode='search';choices=eligible.filter(folder=>(folder.path+' '+folder.code).toLocaleLowerCase().includes(query)).sort((a,b)=>folderSearchScore(a,query)-folderSearchScore(b,query)||folderLabels(a).name.localeCompare(folderLabels(b).name));
    }else if(folderBrowseAll){
      mode='browse';choices=[...eligible].sort((a,b)=>folderLabels(a).name.localeCompare(folderLabels(b).name)||String(a.code).localeCompare(String(b.code)));
    }else{
      const recentIds=readRecentFolderIds(),byId=new Map(eligible.map(folder=>[String(folder.id),folder]));
      const recent=recentIds.map(id=>byId.get(id)).filter(Boolean),suggested=contextFolderSuggestions(eligible),seen=new Set();
      choices=[...recent,...suggested].filter(folder=>{const id=String(folder.id);if(seen.has(id))return false;seen.add(id);return true;});
      const selectedFolderItem=eligible.find(folder=>'folder:'+folder.id===el('Folder').value);
      if(selectedFolderItem&&!seen.has(String(selectedFolderItem.id)))choices.unshift(selectedFolderItem);
    }

    const visibleChoices=choices.slice(0,folderResultLimit);
    el('FolderResults').replaceChildren(...visibleChoices.map(folder=>renderFolderResult(folder,el('Folder').value)));
    el('FolderResults').hidden=visibleChoices.length===0;
    el('FolderShowMore').hidden=choices.length<=folderResultLimit;
    if(!el('FolderShowMore').hidden)el('FolderShowMore').querySelector('span').textContent='Show '+Math.min(6,choices.length-folderResultLimit)+' more of '+choices.length;
    el('FolderSearchClear').hidden=rawQuery==='';
    el('FolderBrowse').hidden=Boolean(query)||eligible.length===0;
    el('FolderBrowse').querySelector('span').textContent=folderBrowseAll?'Show suggested':'Browse all';

    const noResult=eligible.length===0||(mode==='search'&&choices.length===0)||(mode==='suggested'&&choices.length===0);
    el('FolderEmpty').hidden=!noResult;
    el('FolderEmpty').textContent=eligible.length===0?(action==='transfer_copy'?'No other active folder is available. Create a destination through Manage locations, then reopen this profile.':'No active physical folders are available. Create one through Manage locations, then reopen this profile.'):(mode==='search'?'No folder matches “'+rawQuery+'”. Search using the folder name, code, drawer, cabinet, room, or building.':'No recent or related folders yet. Search above or choose Browse all.');

    if(eligible.length===0)el('FolderSearchStatus').textContent='No active physical folders available';
    else if(mode==='search')el('FolderSearchStatus').textContent=choices.length+' '+(choices.length===1?'match':'matches')+(choices.length>folderResultLimit?' · showing '+folderResultLimit:'');
    else if(mode==='browse')el('FolderSearchStatus').textContent='Browsing '+Math.min(folderResultLimit,choices.length)+' of '+choices.length+' folders';
    else el('FolderSearchStatus').textContent=choices.length?Math.min(folderResultLimit,choices.length)+' recent or suggested '+(choices.length===1?'folder':'folders'):'Search or browse '+eligible.length+' available folders';
    folderPreview();
  }
  function edit(next) {
    if(!isCabinet)return;
    message();clearValidation();action=next;el('Form').reset();el('Form').hidden=false;el('ActionPanel').hidden=true;el('Actions').hidden=true;el('History').hidden=true;el('Cancel').hidden=false;el('Save').hidden=false;el('Digital').hidden=true;el('Cabinet').hidden=true;el('Preview').hidden=true;
    el('FolderFields').hidden=!usesFolder();el('Folder').required=usesFolder();el('Folder').disabled=!usesFolder();
    el('BorrowFields').hidden=next!=='borrow_copy';el('Holder').required=next==='borrow_copy';el('Save').disabled=false;
    el('DisposalFields').hidden=next!=='dispose_physical_copy';el('DisposalMethod').required=next==='dispose_physical_copy';el('TypedConfirmation').required=next==='dispose_physical_copy';
    const texts={
      assign_copy:['File physical copy','Select the physical folder where the paper copy was placed.','I verified that the current digital version has a matching physical copy and placed it in the selected folder.'],
      transfer_copy:['Transfer physical copy','Choose the new destination of the same paper copy.','I confirm that I physically moved the same paper copy from its displayed storage location into the selected destination. This is not a version replacement.'],
      borrow_copy:['Record check-out','Identify the person receiving the paper copy and its expected return date.','I confirm that the physical copy was handed to the selected holder.'],
      return_copy:['Record return','Confirm that the previously borrowed paper copy has been received back.','I confirm that the physical copy was received back. If its location is unassigned, I will confirm its folder next.'],
      replace_physical_copy:['Replace physical copy','Confirm that the stored paper copy now matches the latest digital version.','I printed/verified the current version, replaced the stored copy and segregated the old copy as superseded.'],
      dispose_physical_copy:['Dispose physical copy','Document the completed destruction of the real paper copy.','I confirm that the real paper copy has been physically destroyed using the selected method. Remove its active cabinet registration and preserve the disposal evidence.']
    };
    el('FormTitle').textContent=texts[next][0];el('ActionModalTitle').textContent=texts[next][0];el('FormDescription').textContent=texts[next][1];el('Confirmation').textContent=texts[next][2];
    if(next==='assign_copy' && profile.document.digital_destroyed)el('Confirmation').textContent='I verified the existing registered paper copy and placed it in the selected physical folder. The digital file remains destroyed and this does not replace the recorded paper version.';
    if(usesFolder()){folderBrowseAll=false;folderResultLimit=6;folderOptions();}
    if(next==='borrow_copy'){el('Holder').replaceChildren(new Option('Select current holder',''));for(const holder of profile.holders)el('Holder').add(new Option(holder.full_name,String(holder.user_id)));}
    dirty=false;el(usesFolder()?'FolderSearch':next==='borrow_copy'?'Holder':next==='dispose_physical_copy'?'DisposalMethod':'Reason').focus();
  }
  async function openEdit(next){
    if(!profile || busy || optionsLoading)return;
    if((next==='assign_copy' || next==='transfer_copy' || next==='borrow_copy') && !optionsLoaded){
      const serial=requestId;
      optionsLoading=true;message('Loading available locations and account holders…','info');
      try{
        const result=await api('actions/cabinet_fetcher.php?'+new URLSearchParams({action:'get_profile_options',doc_id:String(currentId)}));
        if(serial!==requestId || !actionElement.classList.contains('show'))return;
        profile.folders=result.folders;profile.holders=result.holders;optionsLoaded=true;message();
      }catch(error){if(serial===requestId)message(error.message);return;}
      finally{optionsLoading=false;}
    }
    edit(next);
  }
  const validationMap={
    vcpFolder:'FolderError',
    vcpHolder:'HolderError',
    vcpDisposalMethod:'DisposalMethodError',
    vcpTypedConfirmation:'TypedConfirmationError',
    vcpReason:'ReasonError',
    vcpConfirmed:'ConfirmedError'
  };
  function clearFieldValidation(control){
    if(!control)return;
    control.removeAttribute('aria-invalid');
    control.classList.remove('vcp-invalid');
    if(control.id==='vcpConfirmed')control.closest('.vcp-confirm')?.classList.remove('vcp-invalid');
    if(control.id==='vcpFolder'){el('FolderResults').classList.remove('vcp-invalid');el('FolderSearch').removeAttribute('aria-invalid');}
    const errorKey=validationMap[control.id];
    if(errorKey){const error=el(errorKey);error.textContent='';error.hidden=true;}
  }
  function clearValidation(){
    for(const controlId of Object.keys(validationMap))clearFieldValidation(document.getElementById(controlId));
  }
  function setFieldValidation(control,errorKey,text){
    control.setAttribute('aria-invalid','true');
    control.classList.add('vcp-invalid');
    if(control.id==='vcpConfirmed')control.closest('.vcp-confirm')?.classList.add('vcp-invalid');
    if(control.id==='vcpFolder'){el('FolderResults').classList.add('vcp-invalid');el('FolderSearch').setAttribute('aria-invalid','true');}
    const error=el(errorKey);error.textContent=text;error.hidden=false;
    return control;
  }
  function validateActionForm(){
    clearValidation();
    let firstInvalid=null;
    const invalid=(control,errorKey,text)=>{const result=setFieldValidation(control,errorKey,text);if(!firstInvalid)firstInvalid=result;};
    if(usesFolder() && !selectedFolder())invalid(el('Folder'),'FolderError','Select the physical folder where the paper copy is located.');
    if(action==='borrow_copy' && !el('Holder').value)invalid(el('Holder'),'HolderError','Select the person receiving the physical copy.');
    if(action==='dispose_physical_copy'){
      if(!el('DisposalMethod').value)invalid(el('DisposalMethod'),'DisposalMethodError','Select how the paper copy was physically destroyed.');
      if(el('TypedConfirmation').value.trim()!=='DISPOSE')invalid(el('TypedConfirmation'),'TypedConfirmationError','Type DISPOSE exactly as shown to confirm physical destruction.');
    }
    if(!el('Reason').value.trim())invalid(el('Reason'),'ReasonError','Enter a short factual reason or remark for this action.');
    if(!el('Confirmed').checked)invalid(el('Confirmed'),'ConfirmedError','Confirm that the stated physical-copy action actually occurred.');
    if(firstInvalid){
      message('Review the highlighted fields before saving.');
      const focusTarget=firstInvalid===el('Folder')?el('FolderSearch'):firstInvalid;
      focusTarget.scrollIntoView({behavior:'smooth',block:'center'});focusTarget.focus();
      return false;
    }
    message();return true;
  }
  function askConfirmation({title,text,confirmLabel='Confirm and continue',danger=false}){
    if(!window.DRMSFeedback){message('The system confirmation component is unavailable. Refresh the page and try again.');return Promise.resolve(false);}
    const destructive=danger || /discard|dispose|delete|destroy/i.test(title+' '+confirmLabel);
    return window.DRMSFeedback.confirm({
      title,
      message:text,
      confirmText:confirmLabel,
      cancelText:danger?'Keep physical copy':'Continue editing',
      tone:destructive?'danger':'warning',
      focusConfirm:false
    });
  }
  function actionConfirmation(){
    const names={assign_copy:'File physical copy',transfer_copy:'Transfer physical copy',borrow_copy:'Record check-out',return_copy:'Record return',replace_physical_copy:'Replace physical copy',dispose_physical_copy:'Dispose physical copy'};
    const destructive=action==='dispose_physical_copy';
    return askConfirmation({
      title:names[action]||'Confirm physical record action',
      text:destructive?'This confirms that the real paper copy has already been destroyed. This action will be permanently recorded.':'Review the entered details. Continuing will add this action to the permanent physical-record history.',
      confirmLabel:destructive?'Confirm physical disposal':'Confirm and save',
      danger:destructive
    });
  }
  async function load(id){const serial=++requestId;profile=null;optionsLoaded=false;currentId=id;message();el('Content').hidden=true;el('Loading').hidden=false;el('Digital').hidden=true;el('Cabinet').hidden=true;el('Preview').hidden=true;el('Manage').hidden=true;el('Save').hidden=true;el('Cancel').hidden=true;el('Heading').textContent='Loading record…';
    try{const result=await api('actions/cabinet_fetcher.php?'+new URLSearchParams({action:'get_document_profile',doc_id:String(id)}));if(serial!==requestId)return;profile=result;render();}
    catch(error){if(serial!==requestId)return;el('Loading').hidden=true;el('Heading').textContent='Physical record';message(error.message);}
  }
  window.openPhysicalRecordProfile=id=>{if(busy)return;changed=false;dirty=false;bootstrap.Modal.getOrCreateInstance(modal).show();load(id);};
  el('Manage').addEventListener('click',()=>{
    if(!isCabinet||!profile||busy||el('Manage').hidden)return;
    message();el('ActionMessage').hidden=true;el('ActionFileName').textContent=profile.document.file_name;
    listMode();switchingToAction=true;
    modal.addEventListener('hidden.bs.modal',()=>actionModal.show(),{once:true});
    bootstrap.Modal.getOrCreateInstance(modal).hide();
  });
  el('Assign').addEventListener('click',()=>openEdit('assign_copy'));el('Checkout').addEventListener('click',()=>openEdit(profile.document.physical_status==='Borrowed'?'return_copy':'borrow_copy'));el('Replace').addEventListener('click',()=>openEdit('replace_physical_copy'));
  el('Transfer').addEventListener('click',()=>openEdit('transfer_copy'));
  el('Dispose').addEventListener('click',()=>openEdit('dispose_physical_copy'));
  el('FolderSearch').addEventListener('input',()=>{folderBrowseAll=false;folderResultLimit=6;folderOptions();});
  el('FolderSearchClear').addEventListener('click',()=>{el('FolderSearch').value='';folderBrowseAll=false;folderResultLimit=6;folderOptions();el('FolderSearch').focus();});
  el('FolderBrowse').addEventListener('click',()=>{folderBrowseAll=!folderBrowseAll;folderResultLimit=6;folderOptions();});
  el('FolderShowMore').addEventListener('click',()=>{folderResultLimit+=6;folderOptions();});
  el('Folder').addEventListener('change',()=>{el('Confirmed').checked=false;clearFieldValidation(el('Folder'));clearFieldValidation(el('Confirmed'));folderOptions();});
  el('Cancel').addEventListener('click',async()=>{
    if(busy)return;
    if(!dirty || await askConfirmation({title:'Discard unsaved details?',text:'The information entered for this physical-copy action will not be saved.',confirmLabel:'Discard changes'})){message();clearValidation();listMode();}
  });
  el('Form').addEventListener('input',event=>{dirty=true;clearFieldValidation(event.target);});
  el('Form').addEventListener('change',event=>{dirty=true;clearFieldValidation(event.target);});
  el('Form').addEventListener('submit',async event=>{
    event.preventDefault();if(busy || !profile || !action || !validateActionForm())return;
    if(!await actionConfirmation())return;
    busy=true;el('Save').disabled=true;message();
    const data=new FormData(el('Form'));data.set('action',action);data.set('doc_id',String(profile.document.doc_id));data.set('revision',profile.document.revision);
    if(action==='transfer_copy'){data.set('source_revision',profile.document.folder_revision);data.set('destination_revision',selectedFolder()?.revision||'');}
    if(action==='dispose_physical_copy')data.set('source_revision',profile.document.folder_revision);
    try{
      const result=await api('actions/physical_location_handler.php',{method:'POST',body:data});changed=true;dirty=false;
      if(result.removed){requestId++;profile=null;action='';el('Heading').textContent='Physical copy disposed';el('Loading').hidden=true;el('Content').hidden=true;el('Save').hidden=true;el('Cancel').hidden=true;el('Digital').hidden=true;el('Manage').hidden=true;el('Message').textContent='The physical copy disposal was recorded.';el('Message').dataset.kind='success';el('Message').hidden=false;}
      else{await load(currentId);message();}
      if(window.DRMSFeedback)window.DRMSFeedback.toast(result.message||'Physical record action completed.','success',3000);
      document.dispatchEvent(new CustomEvent('physical-copy-updated'));
      allowActionClose=true;actionModal.hide();
    }
    catch(error){message(error.message);}
    finally{busy=false;saveState();}
  });
  const previewElement=document.getElementById('vcpPreviewModal');
  const previewModal=previewElement?bootstrap.Modal.getOrCreateInstance(previewElement):null;
  const preview=id=>document.getElementById('vcpPreview'+id);
  let previewToken=0,pdfDocument=null,pdfRender=null,pdfPage=1,pdfZoom=1,pdfEnginePromise=null;
  function clearPreview(){
    previewToken++;
    if(pdfRender){try{pdfRender.cancel();}catch(_){}pdfRender=null;}
    if(pdfDocument){pdfDocument.destroy().catch(()=>{});pdfDocument=null;}
    preview('Image').removeAttribute('src');preview('Image').hidden=true;
    preview('Pdf').hidden=true;preview('Fallback').hidden=true;preview('Loading').hidden=false;
  }
  function previewFallback(text){preview('Loading').hidden=true;preview('Image').hidden=true;preview('Pdf').hidden=true;preview('Fallback').textContent=text;preview('Fallback').hidden=false;}
  function previewControls(){
    preview('Page').textContent='Page '+pdfPage+' of '+(pdfDocument?.numPages||1);
    preview('Prev').disabled=pdfPage<=1;preview('Next').disabled=pdfPage>=(pdfDocument?.numPages||1);
    preview('ZoomOut').disabled=pdfZoom<=0.75;preview('ZoomIn').disabled=pdfZoom>=1.5;
  }
  function loadPdfEngine(){
    if(window.FixiePDF?.load)return window.FixiePDF.load();
    if(!pdfEnginePromise)pdfEnginePromise=new Promise((resolve,reject)=>{
      const script=document.createElement('script');script.src='assets/js/local-pdf-loader.js';
      script.onload=()=>window.FixiePDF?.load?resolve(window.FixiePDF.load()):reject(new Error('Local PDF preview unavailable.'));
      script.onerror=()=>reject(new Error('Local PDF preview unavailable.'));document.head.appendChild(script);
    }).catch(error=>{pdfEnginePromise=null;throw error;});
    return pdfEnginePromise;
  }
  async function renderPreviewPage(){
    if(!pdfDocument)return;
    if(pdfRender){try{pdfRender.cancel();}catch(_){}pdfRender=null;}
    const token=previewToken;preview('Loading').hidden=false;previewControls();
    try{
      const page=await pdfDocument.getPage(pdfPage);if(token!==previewToken)return;
      const wrap=preview('Canvas').parentElement,base=page.getViewport({scale:1});
      const fit=Math.min(1.5,Math.max(280,wrap.clientWidth-24)/base.width),viewport=page.getViewport({scale:fit*pdfZoom});
      const ratio=Math.min(window.devicePixelRatio||1,2),canvas=preview('Canvas');
      canvas.width=Math.ceil(viewport.width*ratio);canvas.height=Math.ceil(viewport.height*ratio);
      canvas.style.width=viewport.width+'px';canvas.style.height=viewport.height+'px';
      pdfRender=page.render({canvasContext:canvas.getContext('2d'),viewport,transform:[ratio,0,0,ratio,0,0]});
      await pdfRender.promise;if(token!==previewToken)return;
      pdfRender=null;wrap.scrollTop=0;preview('Loading').hidden=true;
    }catch(error){if(token===previewToken&&error?.name!=='RenderingCancelledException')previewFallback('This PDF could not be previewed. Download it to review the contents.');}
  }
  async function showDigitalPreview(documentRecord){
    clearPreview();const token=previewToken,name=documentRecord.file_name||'Digital file';
    const url='download.php?type=document&record_id='+encodeURIComponent(documentRecord.doc_id);
    if(window.DRMSFilePreview){
      await window.DRMSFilePreview.open({url,name,onClose:()=>{
        switchingToPreview=false;
        if(profile)bootstrap.Modal.getOrCreateInstance(modal).show();
      }});
      return;
    }
    preview('Title').textContent=name;preview('Download').href=url;preview('Download').download=name;
    previewModal.show();
    const extension=name.split('.').pop().toLowerCase();
    if(['png','jpg','jpeg','gif','webp'].includes(extension)){
      const image=preview('Image');image.alt='Preview of '+name;
      image.onload=()=>{if(token===previewToken){image.hidden=false;preview('Loading').hidden=true;}};
      image.onerror=()=>{if(token===previewToken)previewFallback('This image could not be previewed. Download it to review the contents.');};
      image.src=url;return;
    }
    if(extension!=='pdf'){previewFallback('Preview is unavailable for this file type. Download it to view the contents.');return;}
    try{
      const [engine,response]=await Promise.all([loadPdfEngine(),fetch(url,{credentials:'same-origin'})]);
      if(token!==previewToken)return;if(!response.ok)throw new Error('Protected file unavailable.');
      const bytes=new Uint8Array(await response.arrayBuffer());if(token!==previewToken)return;
      const task=engine.getDocument({data:bytes});const document=await task.promise;
      if(token!==previewToken){await document.destroy();return;}
      pdfDocument=document;pdfPage=1;pdfZoom=1;preview('Pdf').hidden=false;await renderPreviewPage();
    }catch(_){if(token===previewToken)previewFallback('This PDF could not be previewed. Download it to review the contents.');}
  }
  el('Preview').addEventListener('click',()=>{
    if(!profile||profile.document.digital_destroyed||busy||action||!previewModal)return;
    const documentRecord=profile.document;switchingToPreview=true;
    modal.addEventListener('hidden.bs.modal',()=>showDigitalPreview(documentRecord),{once:true});
    bootstrap.Modal.getOrCreateInstance(modal).hide();
  });
  preview('Prev').addEventListener('click',()=>{if(pdfDocument&&pdfPage>1){pdfPage--;renderPreviewPage();}});
  preview('Next').addEventListener('click',()=>{if(pdfDocument&&pdfPage<pdfDocument.numPages){pdfPage++;renderPreviewPage();}});
  preview('ZoomOut').addEventListener('click',()=>{if(pdfDocument&&pdfZoom>0.75){pdfZoom=Math.max(0.75,pdfZoom-0.25);renderPreviewPage();}});
  preview('ZoomIn').addEventListener('click',()=>{if(pdfDocument&&pdfZoom<1.5){pdfZoom=Math.min(1.5,pdfZoom+0.25);renderPreviewPage();}});
  previewElement.addEventListener('hidden.bs.modal',()=>{
    clearPreview();switchingToPreview=false;
    if(profile)bootstrap.Modal.getOrCreateInstance(modal).show();
  });
  actionElement.addEventListener('hide.bs.modal',event=>{
    if(allowActionClose){allowActionClose=false;return;}
    if(busy){event.preventDefault();message('Please wait for the current action to finish.');return;}
    if(dirty){
      event.preventDefault();
      askConfirmation({title:'Close without saving?',text:'The physical-copy action details will be discarded.',confirmLabel:'Discard and close'}).then(discard=>{
        if(!discard)return;dirty=false;listMode();allowActionClose=true;actionModal.hide();
      });
    }
  });
  actionElement.addEventListener('hidden.bs.modal',()=>{
    switchingToAction=false;listMode();el('ActionMessage').hidden=true;
    if(profile||changed)bootstrap.Modal.getOrCreateInstance(modal).show();
  });
  modal.addEventListener('hide.bs.modal',event=>{
    if(allowModalClose){allowModalClose=false;return;}
    if(busy){event.preventDefault();message('Please wait for the current action to finish.');return;}
    if(dirty){
      event.preventDefault();
      askConfirmation({title:'Close without saving?',text:'The information entered for this physical-copy action will be discarded.',confirmLabel:'Discard and close'}).then(discard=>{
        if(!discard)return;dirty=false;allowModalClose=true;bootstrap.Modal.getOrCreateInstance(modal).hide();
      });
    }
  });
  modal.addEventListener('hidden.bs.modal',()=>{if(switchingToPreview||switchingToAction)return;requestId++;profile=null;listMode();if(changed && !isCabinet)window.location.reload();});
})();
