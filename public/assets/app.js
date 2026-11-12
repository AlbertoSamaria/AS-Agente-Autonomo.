const app=document.getElementById('app'), login=document.getElementById('login');
const chat=document.getElementById('chat'), input=document.getElementById('message'), form=document.getElementById('composer');
let conversationId=0;
let webSearchEnabled=false;
const selectedDocuments=new Map();

function esc(s){return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
function add(role,text){const e=document.createElement('div');e.className='message '+role;e.innerHTML=`<div class="bubble">${esc(text).replace(/\n/g,'<br>')}</div>`;chat.appendChild(e);chat.scrollTop=chat.scrollHeight;}
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
   const r=await fetch('api/auth.php?action=logout',{method:'POST'});
   const d=await r.json();
   if(!r.ok||!d.success)throw new Error(d.error||'Não foi possível terminar sessão.');
   location.reload();
 }catch(error){alert('Erro ao terminar sessão: '+error.message);button.disabled=false;}
});
const fileInput=document.getElementById('fileInput');
const actionMenu=document.getElementById('actionMenu');
const actionMenuBtn=document.getElementById('actionMenuBtn');
const libraryPanel=document.getElementById('libraryPanel');
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
 if(event.key==='Escape'){closeActionMenu();libraryPanel?.classList.add('hidden');}
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
     renderSelectedDocuments();libraryPanel.classList.add('hidden');
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
     const upload=await fetch('api/upload.php',{method:'POST',body});
     const document=await upload.json();
     if(!upload.ok||!document.success)throw new Error(document.error||'Não foi possível enviar o ficheiro.');
     status.textContent=`A processar ${file.name}…`;
     const process=await fetch('api/process_document.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({document_id:document.document_id})});
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
   const r=await fetch('api/chat.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({message,conversation_id:conversationId,document_ids:Array.from(selectedDocuments.keys())})});
   const d=await r.json();if(d.conversation_id)conversationId=d.conversation_id;add('assistant',d.message||d.error||'Sem resposta.');
 }catch(e){add('assistant','Erro: '+e.message)}finally{send.disabled=false;send.textContent='Enviar';}
}
form?.addEventListener('submit',e=>{e.preventDefault();sendMessage(input.value);});
input?.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();form.requestSubmit();}});
document.querySelectorAll('[data-prompt]').forEach(b=>b.addEventListener('click',()=>sendMessage(b.dataset.prompt)));
document.getElementById('newChat')?.addEventListener('click',()=>{conversationId=0;chat.innerHTML='<div class="welcome"><div class="welcome-logo">SA</div><h2>Nova conversa</h2><p>Qual é a próxima tarefa?</p></div>';});
if(window.SA_USER){document.getElementById('userInfo').textContent=`${SA_USER.name} · ${SA_USER.role}`;}
async function health(){try{const d=await (await fetch('api/health.php')).json();const provider=d.provider||'ollama';const service=d.providers?.[provider]||d.ollama;const label=provider==='llama.cpp'?'llama.cpp':'Ollama';const on=service?.online;document.getElementById('statusDot').className=on?'online':'offline';document.getElementById('statusText').textContent=on?`${label} online`:`${label} offline`;}catch(e){document.getElementById('statusText').textContent='Backend indisponível';}}
health();
