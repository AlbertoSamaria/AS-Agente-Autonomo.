const app=document.getElementById('app'), login=document.getElementById('login');
const chat=document.getElementById('chat'), input=document.getElementById('message'), form=document.getElementById('composer');
let conversationId=0;
let webSearchEnabled=false;
const selectedDocuments=new Map();

function csrfHeaders(extra={}){
  return Object.assign({'X-CSRF-Token': window.SA_CSRF || ''}, extra);
}

function esc(s){return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
function add(role,text){const e=document.createElement('div');e.className='message '+role;e.innerHTML=`<div class="bubble">${esc(text).replace(/\n/g,'<br>')}</div>`;chat.appendChild(e);chat.scrollTop=chat.scrollHeight;return e;}
async function loginNow(){
 const button=document.getElementById('loginBtn'), error=document.getElementById('loginError');
 button.disabled=true;error.textContent='';
 try{
   const r=await fetch('api/auth.php?action=login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:document.getElementById('email').value,password:document.getElementById('password').value})});
   const d=await r.json();
   if(!r.ok||!d.success){error.textContent=d.error||'Não foi possível iniciar sessão.';return;}
   location.reload();
 }catch(e){error.textContent='Erro ao iniciar sessão: '+e.message;}finally{button.disabled=false;}
}
document.getElementById('loginBtn')?.addEventListener('click',loginNow);
document.getElementById('password')?.addEventListener('keydown',e=>{if(e.key==='Enter')loginNow();});
document.getElementById('logoutBtn')?.addEventListener('click',async e=>{
 const button=e.currentTarget;
 button.disabled=true;
 try{
   const r=await fetch('api/auth.php?action=logout',{method:'POST',headers:csrfHeaders()});
   const d=await r.json();
   if(!r.ok||!d.success)throw new Error(d.error||'Não foi possível terminar sessão.');
   location.reload();
 }catch(error){alert('Erro ao terminar sessão: '+error.message);button.disabled=false;}
});

const adminPanel=document.getElementById('adminPanel');
const adminPanelBtn=document.getElementById('adminPanelBtn');
const closeAdminPanelButton=document.getElementById('closeAdminPanel');
const adminUsersTable=document.getElementById('adminUsersTable');
const adminApprovals=document.getElementById('adminApprovals');
const adminCreateUserForm=document.getElementById('adminCreateUserForm');
const permissionUserSelect=document.getElementById('permissionUserSelect');
const permissionList=document.getElementById('permissionList');
const adminAuditList=document.getElementById('adminAuditList');
const adminUserSearch=document.getElementById('adminUserSearch');
const adminUserStatusFilter=document.getElementById('adminUserStatusFilter');
const approvalStatusFilter=document.getElementById('approvalStatusFilter');
const auditSearch=document.getElementById('auditSearch');
const auditStatusFilter=document.getElementById('auditStatusFilter');
const permissionSearch=document.getElementById('permissionSearch');

function toggleAdminPanel(forceOpen){
 if(!adminPanel)return;
 const shouldOpen=typeof forceOpen==='boolean'?forceOpen:adminPanel.classList.contains('hidden');
 adminPanel.classList.toggle('hidden',!shouldOpen);
}
adminPanelBtn?.addEventListener('click',()=>toggleAdminPanel());
closeAdminPanelButton?.addEventListener('click',()=>toggleAdminPanel(false));

async function loadAdminData(){
 if(!adminPanel || !window.SA_USER || window.SA_USER.role!=='admin')return;
 try{
   const usersRequest=await fetch('api/admin.php?action=users');
   const usersData=await usersRequest.json();
   if(!usersRequest.ok||!usersData.success)throw new Error(usersData.error||'Não foi possível carregar os utilizadores.');
   const approvalsRequest=await fetch('api/admin.php?action=approvals');
   const approvalsData=await approvalsRequest.json();
   if(!approvalsRequest.ok||!approvalsData.success)throw new Error(approvalsData.error||'Não foi possível carregar as aprovações.');
   const auditRequest=await fetch('api/admin.php?action=audit');
   const auditData=await auditRequest.json();
   if(!auditRequest.ok||!auditData.success)throw new Error(auditData.error||'Não foi possível carregar a auditoria.');

   renderAdminUsers(usersData.users||[]);
   renderAdminApprovals(approvalsData.approvals||[]);
   renderAuditLog(auditData.audit||[]);
   populatePermissionUserSelect(usersData.users||[]);
 }catch(error){
   if(adminUsersTable)adminUsersTable.innerHTML=`<div class="empty-state">Erro: ${esc(error.message)}</div>`;
   if(adminApprovals)adminApprovals.innerHTML=`<div class="empty-state">Erro: ${esc(error.message)}</div>`;
   if(adminAuditList)adminAuditList.innerHTML=`<div class="empty-state">Erro: ${esc(error.message)}</div>`;
 }
}

function renderAdminUsers(users){
 if(!adminUsersTable)return;
 const query=(adminUserSearch?.value||'').trim().toLowerCase();
 const statusFilter=(adminUserStatusFilter?.value||'all');
 const filtered=users.filter(user=>{
   const matchesQuery=!query || `${user.name} ${user.email}`.toLowerCase().includes(query);
   const matchesStatus = statusFilter === 'all' || (statusFilter === 'active' ? !!user.active : !user.active);
   return matchesQuery && matchesStatus;
 });
 if(!filtered.length){adminUsersTable.innerHTML='<div class="empty-state">Nenhum utilizador encontrado.</div>';return;}
 const table=document.createElement('table');
 table.className='admin-table';
 const header=document.createElement('thead');
 header.innerHTML=`<tr><th>Nome</th><th>Email</th><th>Role</th><th>Estado</th><th>Ação</th></tr>`;
 table.appendChild(header);
 const tbody=document.createElement('tbody');
 filtered.forEach(user=>{
   const row=document.createElement('tr');
   row.dataset.userId=String(user.id);
   const name=document.createElement('td');name.textContent=user.name;
   const email=document.createElement('td');email.textContent=user.email;
   const roleCell=document.createElement('td');
   const select=document.createElement('select');
   ['viewer','operator','manager','admin'].forEach(role=>{
     const option=document.createElement('option');
     option.value=role;option.textContent=role;option.selected=role===user.role;
     select.appendChild(option);
   });
   select.addEventListener('change',async event=>{
     const value=event.target.value;
     try{
       const response=await fetch('api/admin.php?action=set-role',{method:'POST',headers:Object.assign({'Content-Type':'application/json'},csrfHeaders()),body:JSON.stringify({user_id:Number(user.id),role:value})});
       const data=await response.json();
       if(!response.ok||!data.success)throw new Error(data.error||'Não foi possível alterar a role.');
       await loadAdminData();
     }catch(error){alert(error.message);}
   });
   roleCell.appendChild(select);
   const statusCell=document.createElement('td');
   const status=document.createElement('span');
   status.className=`admin-status ${user.active?'active':'inactive'}`;
   status.textContent=user.active?'Ativo':'Inativo';
   statusCell.appendChild(status);
   const actionCell=document.createElement('td');
   const toggle=document.createElement('button');
   toggle.type='button';
   toggle.className='admin-toggle';
   toggle.textContent=user.active?'Desativar':'Ativar';
   toggle.disabled=Number(user.id)===Number(window.SA_USER.id);
   toggle.addEventListener('click',async()=>{
     try{
       const response=await fetch('api/admin.php?action=toggle-user',{method:'POST',headers:Object.assign({'Content-Type':'application/json'},csrfHeaders()),body:JSON.stringify({user_id:Number(user.id),active:!user.active})});
       const data=await response.json();
       if(!response.ok||!data.success)throw new Error(data.error||'Não foi possível alterar o estado do utilizador.');
       await loadAdminData();
     }catch(error){alert(error.message);}
   });
   actionCell.appendChild(toggle);
   row.append(name,email,roleCell,statusCell,actionCell);
   tbody.appendChild(row);
 });
 table.appendChild(tbody);
 adminUsersTable.replaceChildren(table);
}

function renderAdminApprovals(approvals){
 if(!adminApprovals)return;
 const filter=(approvalStatusFilter?.value || 'all');
 const query=(adminUserSearch?.value || '').trim().toLowerCase();
 const filtered=approvals.filter(approval=>{
   const matchesStatus = filter === 'all' || approval.status === filter;
   const haystack = `${approval.tool_name || ''} ${approval.action_type || ''} ${approval.requester_name || ''}`.toLowerCase();
   const matchesQuery = !query || haystack.includes(query);
   return matchesStatus && matchesQuery;
 });
 if(!filtered.length){adminApprovals.innerHTML='<div class="empty-state">Nenhuma aprovação encontrada.</div>';return;}
 const list=document.createElement('div');
 list.className='admin-approvals';
 filtered.forEach(approval=>{
   const item=document.createElement('div');
   item.className='approval-item';
   const title=document.createElement('strong');
   title.textContent=`${approval.tool_name} · ${approval.action_type}`;
   const meta=document.createElement('div');
   meta.className='approval-meta';
   const requester=approval.requester_name||'Sistema';
   meta.textContent=`Pedido por ${requester} • ${new Date(approval.created_at).toLocaleString('pt-PT')}`;
   const status=document.createElement('div');
   status.className=`admin-status ${approval.status==='approved'?'active':approval.status==='rejected'?'inactive':'pending'}`;
   status.textContent=approval.status==='approved'?'Aprovado':approval.status==='rejected'?'Rejeitado':approval.status==='pending'?'Pendente':'Expirada';
   const payload=document.createElement('div');
   payload.className='approval-meta';
   payload.textContent=`Detalhes: ${esc(approval.arguments_json ? JSON.stringify(approval.arguments_json) : '{}')}`;
   const actions=document.createElement('div');
   actions.className='approval-actions';
   if(approval.status==='pending'){
     const approve=document.createElement('button');
     approve.type='button';approve.className='admin-approve-btn';approve.textContent='Aprovar';
     approve.addEventListener('click',async()=>{ await decideApproval(Number(approval.id),'approved'); });
     const reject=document.createElement('button');
     reject.type='button';reject.className='admin-reject-btn';reject.textContent='Rejeitar';
     reject.addEventListener('click',async()=>{ await decideApproval(Number(approval.id),'rejected'); });
     actions.appendChild(approve);actions.appendChild(reject);
   }
   item.append(title,meta,status,payload,actions);
   list.appendChild(item);
 });
 adminApprovals.replaceChildren(list);
}

function populatePermissionUserSelect(users){
 if(!permissionUserSelect)return;
 const selectedValue=permissionUserSelect.value || String(users[0]?.id || '');
 permissionUserSelect.innerHTML='';
 users.forEach(user=>{
   const option=document.createElement('option');
   option.value=String(user.id);option.textContent=`${user.name} (${user.role})`;
   if(String(user.id)===selectedValue)option.selected=true;
   permissionUserSelect.appendChild(option);
 });
 if(!users.length){permissionUserSelect.innerHTML='<option value="">Sem utilizadores</option>';}
 permissionUserSelect.onchange=async()=>{
   const userId=permissionUserSelect.value;
   if(!userId)return;
   await loadPermissionPanel(Number(userId));
 };
 if(users.length){
   loadPermissionPanel(Number(permissionUserSelect.value || users[0].id));
 }
}

async function loadPermissionPanel(userId){
 if(!permissionList)return;
 try{
   const response=await fetch(`api/admin.php?action=permissions&user_id=${encodeURIComponent(userId)}`);
   const data=await response.json();
   if(!response.ok||!data.success)throw new Error(data.error||'Não foi possível carregar as permissões.');
   renderPermissionList(data.permissions||[], data.effective||{});
 }catch(error){
   permissionList.innerHTML=`<div class="empty-state">Erro: ${esc(error.message)}</div>`;
 }
}

function renderPermissionList(permissions, effective){
 if(!permissionList)return;
 const query=(permissionSearch?.value || '').trim().toLowerCase();
 const filtered = permissions.filter(permission => !query || permission.toLowerCase().includes(query));
 if(!filtered.length){permissionList.innerHTML='<div class="empty-state">Nenhuma permissão encontrada.</div>';return;}
 const list=document.createElement('div');
 list.className='permission-list';
 filtered.forEach(permission=>{
   const item=document.createElement('label');
   item.className='permission-item';
   const name=document.createElement('span');name.textContent=permission;
   const checkbox=document.createElement('input');
   checkbox.type='checkbox';checkbox.checked=Boolean(effective[permission]);
   checkbox.addEventListener('change',async()=>{
     const userId=Number(permissionUserSelect?.value || 0);
     if(!userId){return;}
     try{
       const response=await fetch('api/admin.php?action=set-user-permission',{method:'POST',headers:Object.assign({'Content-Type':'application/json'},csrfHeaders()),body:JSON.stringify({user_id:userId,permission,granted:checkbox.checked})});
       const data=await response.json();
       if(!response.ok||!data.success)throw new Error(data.error||'Não foi possível guardar a permissão.');
       await loadPermissionPanel(userId);
     }catch(error){alert(error.message);}
   });
   item.append(name,checkbox);list.appendChild(item);
 });
 permissionList.replaceChildren(list);
}

function renderAuditLog(audit){
 if(!adminAuditList)return;
 const query=(auditSearch?.value || '').trim().toLowerCase();
 const statusFilter=(auditStatusFilter?.value || 'all');
 const filtered = audit.filter(entry => {
   const haystack = `${entry.action || ''} ${entry.actor_name || ''} ${entry.target_name || ''}`.toLowerCase();
   const matchesQuery = !query || haystack.includes(query);
   const matchesStatus = statusFilter === 'all' || entry.status === statusFilter;
   return matchesQuery && matchesStatus;
 });
 if(!filtered.length){adminAuditList.innerHTML='<div class="empty-state">Sem eventos de auditoria.</div>';return;}
 const list=document.createElement('div');
 list.className='audit-list';
 filtered.forEach(entry=>{
   const item=document.createElement('div');
   item.className='audit-item';
   const title=document.createElement('strong');
   title.textContent=`${entry.action}`;
   const meta=document.createElement('div');
   meta.className='audit-meta';
   const actor=entry.actor_name||'Sistema';
   const target=entry.target_name||'—';
   meta.textContent=`${actor} → ${target} • ${new Date(entry.created_at).toLocaleString('pt-PT')} • ${entry.status}`;
   const details=document.createElement('div');
   details.className='audit-meta';
   details.textContent=entry.details_json ? JSON.stringify(entry.details_json) : 'Sem detalhes';
   item.append(title,meta,details);list.appendChild(item);
 });
 adminAuditList.replaceChildren(list);
}

async function decideApproval(id,decision){
 try{
   const response=await fetch('api/approvals.php',{method:'POST',headers:Object.assign({'Content-Type':'application/json'},csrfHeaders()),body:JSON.stringify({id,decision})});
   const data=await response.json();
   if(!response.ok||!data.success)throw new Error(data.error||'Não foi possível decidir a aprovação.');
   await loadAdminData();
 }catch(error){alert(error.message);}
}

adminCreateUserForm?.addEventListener('submit',async event=>{
 event.preventDefault();
 const formData=new FormData(adminCreateUserForm);
 const payload={
   name: String(formData.get('name')||'').trim(),
   email: String(formData.get('email')||'').trim(),
   password: String(formData.get('password')||''),
   role: String(formData.get('role')||'viewer')
 };
 if(!payload.name||!payload.email||payload.password.length<8){
   alert('Preenche todos os campos e usa uma palavra-passe com pelo menos 8 caracteres.');
   return;
 }
 try{
   const response=await fetch('api/admin.php?action=create-user',{method:'POST',headers:Object.assign({'Content-Type':'application/json'},csrfHeaders()),body:JSON.stringify(payload)});
   const data=await response.json();
   if(!response.ok||!data.success)throw new Error(data.error||'Não foi possível criar o utilizador.');
   adminCreateUserForm.reset();
   await loadAdminData();
 }catch(error){alert(error.message);}
});

if(adminUserSearch)adminUserSearch.addEventListener('input',()=>loadAdminData());
if(adminUserStatusFilter)adminUserStatusFilter.addEventListener('change',()=>loadAdminData());
if(approvalStatusFilter)approvalStatusFilter.addEventListener('change',()=>loadAdminData());
if(auditSearch)auditSearch.addEventListener('input',()=>loadAdminData());
if(auditStatusFilter)auditStatusFilter.addEventListener('change',()=>loadAdminData());
if(permissionSearch)permissionSearch.addEventListener('input',()=>{
  const userId=Number(permissionUserSelect?.value || 0);
  if(userId>0)loadPermissionPanel(userId);
});
if(window.SA_USER && window.SA_USER.role==='admin'){loadAdminData();}
const fileInput=document.getElementById('fileInput');
const actionMenu=document.getElementById('actionMenu');
const actionMenuBtn=document.getElementById('actionMenuBtn');
const libraryPanel=document.getElementById('libraryPanel');
const navigationPanel=document.getElementById('navigationPanel');
const navigationPanelTitle=document.getElementById('navigationPanelTitle');
const projectsPanel=document.getElementById('projectsPanel');
const pluginsPanel=document.getElementById('pluginsPanel');
const menuBtn=document.getElementById('menuBtn');
const sidebar=document.querySelector('.sidebar');

function closeNavigationPanel(){
 navigationPanel?.classList.add('hidden');
}
function openNavigationPanel(section){
 if(!navigationPanel||!navigationPanelTitle)return;
 libraryPanel?.classList.add('hidden');
 projectsPanel?.classList.add('hidden');
 pluginsPanel?.classList.add('hidden');
 if(section==='library'){
   navigationPanelTitle.textContent='Biblioteca';
   navigationPanel.classList.remove('hidden');
   showLibrary();
 }else if(section==='projects'){
   navigationPanelTitle.textContent='Projectos';
   projectsPanel?.classList.remove('hidden');
   navigationPanel.classList.remove('hidden');
 }else if(section==='plugins'){
   navigationPanelTitle.textContent='Plugins';
   pluginsPanel?.classList.remove('hidden');
   navigationPanel.classList.remove('hidden');
 }
 sidebar?.classList.remove('open');
 app?.classList.remove('sidebar-open');
 document.getElementById('newChat')?.classList.remove('active');
 document.getElementById('newChat')?.removeAttribute('aria-current');
 document.querySelectorAll('.side-nav-item[data-panel]').forEach(item=>{
   item.classList.toggle('active',item.dataset.panel===section);
   if(item.dataset.panel===section)item.setAttribute('aria-current','page');
   else item.removeAttribute('aria-current');
 });
}
document.getElementById('navLibrary')?.addEventListener('click',()=>openNavigationPanel('library'));
document.getElementById('navProjects')?.addEventListener('click',()=>openNavigationPanel('projects'));
document.getElementById('navPlugins')?.addEventListener('click',()=>openNavigationPanel('plugins'));
document.getElementById('closeNavigationPanel')?.addEventListener('click',closeNavigationPanel);
navigationPanel?.addEventListener('click',event=>{
 if(event.target===navigationPanel)closeNavigationPanel();
 if(event.target.closest('[data-prompt]'))closeNavigationPanel();
});
menuBtn?.addEventListener('click',()=>{
 sidebar?.classList.toggle('open');
 app?.classList.toggle('sidebar-open',sidebar?.classList.contains('open'));
});
app?.addEventListener('click',event=>{
 if(app.classList.contains('sidebar-open')&&!sidebar?.contains(event.target)&&event.target!==menuBtn){
   sidebar?.classList.remove('open');
   app.classList.remove('sidebar-open');
 }
});
function closeActionMenu(){
 actionMenu?.classList.add('hidden');
 actionMenuBtn?.setAttribute('aria-expanded','false');
}
actionMenuBtn?.addEventListener('click',()=>{
 const open=actionMenu.classList.toggle('hidden')===false;
 actionMenuBtn.setAttribute('aria-expanded',String(open));
});
document.addEventListener('click',event=>{
 if(!event.target.closest('.action-menu'))closeActionMenu();
});
document.addEventListener('keydown',event=>{
 if(event.key==='Escape'){closeActionMenu();closeNavigationPanel();sidebar?.classList.remove('open');app?.classList.remove('sidebar-open');}
});
function renderSelectedDocuments(){
 const container=document.getElementById('selectedDocuments');
 container.replaceChildren();
 for(const [id,name] of selectedDocuments){
   const chip=document.createElement('span');chip.className='document-chip';
   const label=document.createElement('span');label.textContent=name;
   const remove=document.createElement('button');remove.type='button';remove.textContent='×';remove.setAttribute('aria-label',`Remover ${name}`);
   remove.addEventListener('click',()=>{selectedDocuments.delete(id);renderSelectedDocuments();});
   chip.append(label,remove);container.appendChild(chip);
 }
}
async function showLibrary(){
 if(!libraryPanel)return;
 libraryPanel.classList.remove('hidden');
 libraryPanel.textContent='A carregar biblioteca…';
 try{
   const response=await fetch('api/documents.php');
   const data=await response.json();
   if(!response.ok||!data.success)throw new Error(data.error||'Não foi possível carregar a biblioteca.');
   libraryPanel.replaceChildren();
   const title=document.createElement('strong');title.textContent='A tua biblioteca';libraryPanel.appendChild(title);
   if(!data.documents.length){
     const empty=document.createElement('p');empty.textContent='Ainda não há ficheiros na biblioteca.';libraryPanel.appendChild(empty);return;
   }
   const list=document.createElement('div');list.className='library-list';
   for(const document of data.documents){
     const label=document.createElement('label');label.className='library-item';
     const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.value=document.id;checkbox.disabled=document.status!=='ready';checkbox.checked=selectedDocuments.has(String(document.id));
     const name=document.createElement('span');name.textContent=document.original_name;
     const status=document.createElement('small');status.textContent=document.status==='ready'?'Pronto':document.status==='processing'?'A processar':document.status==='pending'?'Pendente':'Falhou';
     label.append(checkbox,name,status);list.appendChild(label);
   }
   libraryPanel.appendChild(list);
   const add=document.createElement('button');add.type='button';add.className='library-add';add.textContent='Adicionar seleccionados';
   add.addEventListener('click',()=>{
     list.querySelectorAll('input:checked').forEach(checkbox=>{
       const document=data.documents.find(item=>String(item.id)===checkbox.value);
       if(document)selectedDocuments.set(String(document.id),document.original_name);
     });
     renderSelectedDocuments();closeNavigationPanel();
   });
   libraryPanel.appendChild(add);
 }catch(error){libraryPanel.textContent='Erro: '+error.message;}
}
actionMenu?.addEventListener('click',event=>{
 const button=event.target.closest('[data-action]');
 if(!button)return;
 switch(button.dataset.action){
   case 'upload':fileInput?.click();closeActionMenu();break;
   case 'library':closeActionMenu();showLibrary();break;
   case 'voice':closeActionMenu();voiceBtn?.click();break;
   case 'web-search':
     webSearchEnabled=!webSearchEnabled;
     button.setAttribute('aria-pressed',String(webSearchEnabled));
     button.classList.toggle('active',webSearchEnabled);
     button.querySelector('span:last-child').textContent=webSearchEnabled?'Pesquisa na web · activa':'Pesquisa na web';
     break;
 }
});
fileInput?.addEventListener('change',async()=>{
 const files=Array.from(fileInput.files||[]);
 for(const file of files){
   const status=document.getElementById('uploadStatus');
   status.textContent=`A enviar ${file.name}…`;
   try{
     const body=new FormData();body.append('file',file);
     const upload=await fetch('api/upload.php',{method:'POST',headers:csrfHeaders(),body});
     const document=await upload.json();
     if(!upload.ok||!document.success)throw new Error(document.error||'Não foi possível enviar o ficheiro.');
     status.textContent=`A processar ${file.name}…`;
     const process=await fetch('api/process_document.php',{method:'POST',headers:Object.assign({'Content-Type':'application/json'},csrfHeaders()),body:JSON.stringify({document_id:document.document_id})});
     const result=await process.json();
     if(!process.ok||!result.success)throw new Error(result.error||'Não foi possível processar o ficheiro.');
     selectedDocuments.set(String(document.document_id),document.name);
     renderSelectedDocuments();
     status.textContent=`${file.name} pronto para consulta.`;
   }catch(error){status.textContent=`Erro em ${file.name}: ${error.message}`;}
 }
 fileInput.value='';
});
const voiceBtn=document.getElementById('voiceBtn');
voiceBtn?.addEventListener('click',()=>{
 const SpeechRecognition=window.SpeechRecognition||window.webkitSpeechRecognition;
 if(!SpeechRecognition){document.getElementById('uploadStatus').textContent='O ditado por voz não é suportado neste navegador.';return;}
 const recognition=new SpeechRecognition();
 recognition.lang='pt-PT';recognition.interimResults=false;recognition.maxAlternatives=1;
 voiceBtn.disabled=true;document.getElementById('uploadStatus').textContent='A ouvir… o reconhecimento de voz é fornecido pelo navegador.';
 recognition.onresult=event=>{
   const spoken=event.results[0][0].transcript;
   input.value=(input.value.trim()?input.value.trim()+' ':'')+spoken;
   input.focus();
   document.getElementById('uploadStatus').textContent='Ditado inserido.';
 };
 recognition.onerror=event=>{document.getElementById('uploadStatus').textContent='Erro no ditado: '+event.error;};
 recognition.onend=()=>{voiceBtn.disabled=false;};
 try{recognition.start();}catch(error){
   voiceBtn.disabled=false;
   document.getElementById('uploadStatus').textContent='Não foi possível iniciar o ditado: '+error.message;
 }
});
async function sendMessage(text){
 if(!text.trim())return;
 const message=webSearchEnabled?'Pesquisa na web: '+text.trim():text.trim();
 document.querySelector('.welcome')?.remove();add('user',message);input.value='';
 const send=document.getElementById('sendBtn');send.disabled=true;send.textContent='…';
 try{
   const response=await fetch('api/stream.php',{method:'POST',headers:Object.assign({'Content-Type':'application/json'},csrfHeaders()),body:JSON.stringify({message,conversation_id:conversationId,document_ids:Array.from(selectedDocuments.keys())})});
   if(!response.ok||!response.body){
     const errorData=await response.json().catch(()=>({}));
     throw new Error(errorData.error||'Não foi possível iniciar o streaming.');
   }
   const assistant=add('assistant','');
   const bubble=assistant.querySelector('.bubble');
   const reader=response.body.getReader();
   const decoder=new TextDecoder();
   let buffer='';
   let streamedContent='';
   let streamError='';
   let completed=false;
   const handleEvent=raw=>{
     const dataLine=raw.split(/\r?\n/).find(line=>line.startsWith('data:'));
     if(!dataLine)return;
     let event;
     try{event=JSON.parse(dataLine.slice(5).trim());}catch(error){throw new Error('Resposta de streaming inválida.');}
     if(typeof event.content==='string'){
       streamedContent+=event.content;
       bubble.textContent=streamedContent;
       chat.scrollTop=chat.scrollHeight;
     }
     if(event.conversation_id)conversationId=event.conversation_id;
     if(event.error)streamError=event.error;
     if(event.done)completed=true;
   };
   while(true){
     const {value,done}=await reader.read();
     buffer+=decoder.decode(value||new Uint8Array(),{stream:!done});
     let separator;
     while((separator=buffer.indexOf('\n\n'))>=0){
       handleEvent(buffer.slice(0,separator));
       buffer=buffer.slice(separator+2);
     }
     if(done)break;
   }
   if(buffer.trim())handleEvent(buffer);
   if(!streamedContent&&!streamError)bubble.textContent='Sem resposta.';
   if(streamError)bubble.textContent=(streamedContent?streamedContent+'\n\n':'')+'Erro: '+streamError;
   if(completed)await loadConversationList();
 }catch(e){add('assistant','Erro: '+e.message)}finally{send.disabled=false;send.textContent='Enviar';}
}
form?.addEventListener('submit',e=>{e.preventDefault();sendMessage(input.value);});
input?.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();form.requestSubmit();}});
document.querySelectorAll('[data-prompt]').forEach(b=>b.addEventListener('click',()=>sendMessage(b.dataset.prompt)));
const conversationList=document.getElementById('conversationList');
async function loadConversationList(){
 if(!conversationList)return;
 try{
   const response=await fetch('api/conversations.php');
   const data=await response.json();
   if(!response.ok||!data.success)throw new Error(data.error||'Não foi possível carregar conversas.');
   conversationList.replaceChildren();
   if(!data.conversations.length){
     const empty=document.createElement('p');empty.className='conversation-list-status';empty.textContent='Ainda sem conversas.';
     conversationList.appendChild(empty);return;
   }
   data.conversations.forEach(conversation=>{
     const item=document.createElement('button');
     item.type='button';item.className='conversation-item';
     item.textContent=conversation.title||'Nova conversa';
     item.title=item.textContent;
     item.classList.toggle('active',Number(conversation.id)===conversationId);
     item.addEventListener('click',()=>loadConversation(Number(conversation.id)));
     conversationList.appendChild(item);
   });
 }catch(error){
   conversationList.replaceChildren();
   const message=document.createElement('p');message.className='conversation-list-status error';message.textContent='Erro ao carregar conversas.';
   conversationList.appendChild(message);
 }
}
async function loadConversation(id){
 try{
   const response=await fetch(`api/conversations.php?id=${encodeURIComponent(id)}`);
   const data=await response.json();
   if(!response.ok||!data.success)throw new Error(data.error||'Não foi possível abrir a conversa.');
   conversationId=Number(data.conversation.id);
   chat.replaceChildren();
   data.messages.forEach(message=>{
     if(message.role==='user'||message.role==='assistant')add(message.role,message.content);
   });
   document.querySelectorAll('.side-nav-item').forEach(item=>{
     item.classList.remove('active');item.removeAttribute('aria-current');
   });
   closeNavigationPanel();
   await loadConversationList();
 }catch(error){add('assistant','Erro ao abrir conversa: '+error.message);}
}
document.getElementById('newChat')?.addEventListener('click',()=>{
 conversationId=0;
 closeNavigationPanel();
 document.querySelectorAll('.side-nav-item').forEach(item=>{
   item.classList.remove('active');
   item.removeAttribute('aria-current');
 });
 document.getElementById('newChat')?.classList.add('active');
 document.getElementById('newChat')?.setAttribute('aria-current','page');
 sidebar?.classList.remove('open');
 app?.classList.remove('sidebar-open');
 chat.innerHTML='<div class="welcome"><div class="welcome-logo">SA</div><h2>Nova conversa</h2><p>Qual é a próxima tarefa?</p></div>';
 loadConversationList();
});
if(window.SA_USER){document.getElementById('userInfo').textContent=`${SA_USER.name} · ${SA_USER.role}`;}
if(window.SA_USER)loadConversationList();
async function health(){try{const d=await (await fetch('api/health.php')).json();const provider=d.provider||'ollama';const service=d.providers?.[provider]||d.ollama;const label=provider==='llama.cpp'?'llama.cpp':'Ollama';const on=service?.online;document.getElementById('statusDot').className=on?'online':'offline';document.getElementById('statusText').textContent=on?`${label} online`:`${label} offline`;}catch(e){document.getElementById('statusText').textContent='Backend indisponível';}}
health();
