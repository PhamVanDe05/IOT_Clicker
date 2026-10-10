'use strict';
const $ = id => document.getElementById(id);
const state = {uid:'',csrf:'',session:null,mode:'WAIT_SESSION',student:'',question:null,input:'',selected:'',pending:null,online:false,busy:false,message:'',storage:true};
const keys=[...document.querySelectorAll('[data-key]')];
function storageKey(){return 'clicker-pending-'+state.uid;}
function savePending(){try{if(state.pending)localStorage.setItem(storageKey(),JSON.stringify(state.pending));else localStorage.removeItem(storageKey());}catch{state.storage=false;}}
function render(){
  $('connection').textContent=state.online?'Đã kết nối':'Mất kết nối';
  $('session-label').textContent=state.session?'BUỔI #'+state.session:'CHỜ BUỔI HỌC';
  let title,value,detail,status=state.message;
  if(state.pending){title=state.pending.action==='login'?'ĐANG ĐĂNG NHẬP':'ĐANG GỬI ĐÁP ÁN';value=state.pending.student_id||state.pending.answer;detail='Chờ server xác nhận đã lưu.';if(!state.online)status='Đang thử lại, giữ trang này mở…';}
  else if(state.mode==='FINISHED'){title='BUỔI HỌC KẾT THÚC';value=state.student||'Cảm ơn bạn';detail='Chờ giảng viên tạo buổi học mới.';}
  else if(!state.student){title=state.mode==='LOGIN_OPEN'?'NHẬP MSSV':'CHỜ MỞ ĐĂNG NHẬP';value=state.mode==='LOGIN_OPEN'?(state.input||'_'):'Sẵn sàng';detail=state.mode==='LOGIN_OPEN'?'Nhấn CONFIRM để đăng nhập.':'Giảng viên sẽ mở đăng nhập trên website.';}
  else if(state.question){title='CÂU HỎI '+state.question.question_no;value=state.question.accepted_answer||state.selected||'Chọn A / B / C / D';detail='1=A   2=B   3=C   4=D';if(state.question.accepted_answer)status='ĐÃ LƯU ✓ · Chờ câu tiếp theo';}
  else{title='ĐĂNG NHẬP THÀNH CÔNG';value=state.student;detail='Chờ câu hỏi tiếp theo…';}
  $('screen-title').textContent=title;$('screen-value').textContent=value;$('screen-detail').textContent=detail;$('screen-status').textContent=status;
  const login=!state.student&&state.mode==='LOGIN_OPEN';
  const choosing=!!state.student&&!!state.question&&!state.question.accepted_answer;
  for(const key of keys){key.disabled=!!state.pending||!(login||(choosing&&'1234'.includes(key.dataset.key)));key.classList.toggle('selected',choosing&&state.selected==='ABCD'[Number(key.dataset.key)-1]);}
  $('confirm').disabled=!!state.pending||!(login&&state.input||choosing&&state.selected);
  $('clear').disabled=!login||!!state.pending||!state.input;
}
async function request(payload){
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),5000);
  try{
    const response=await fetch('phone-api.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrf},body:JSON.stringify(payload),signal:controller.signal});
    const result=await response.json();state.online=true;
    if(!response.ok||!result.ok){const error=new Error(result.error||'Server chưa xử lý được.');error.permanent=response.status>=400&&response.status<500&&![408,429].includes(response.status);throw error;}
    return result;
  }catch(error){if(!error.permanent)state.online=false;throw error;}finally{clearTimeout(timeout);}
}
function applyPoll(data){
  const changed=data.session_id!==state.session;
  if(changed){state.input='';state.selected='';state.message='';}
  if(data.question?.question_id!==state.question?.question_id){state.selected='';if(!changed)state.message='';}
  state.session=data.session_id;state.mode=data.state;state.student=data.student_id||'';state.question=data.question;
}
async function tick(){
  if(!state.uid||state.busy)return;state.busy=true;
  try{
    if(state.pending){
      const packet=state.pending,result=await request(packet);
      const matching=result.session_id===packet.session_id&&(packet.action==='login'?result.type==='LOGIN_OK'&&result.student_id===packet.student_id:result.type==='ANSWER_ACK'&&result.question_id===packet.question_id&&result.answer===packet.answer);
      if(!matching)throw new Error('Xác nhận không khớp. Đang thử lại…');
      state.message=packet.action==='login'?'Đăng nhập thành công.':'ĐÃ LƯU ✓';state.pending=null;savePending();
    }
    applyPoll(await request({action:'poll'}));
  }catch(error){state.message=error.message;if(error.permanent){state.pending=null;savePending();}}
  finally{state.busy=false;render();}
}
function digit(value){
  if(state.pending)return;
  if(!state.student&&state.mode==='LOGIN_OPEN'&&state.input.length<20){state.input+=value;state.message='';}
  else if(state.student&&state.question&&!state.question.accepted_answer&&'1234'.includes(value)){state.selected='ABCD'[Number(value)-1];state.message='';}
  render();
}
function confirm(){
  if(state.pending||$('confirm').disabled)return;
  if(!state.student&&state.mode==='LOGIN_OPEN'&&state.input)state.pending={action:'login',session_id:state.session,student_id:state.input};
  else if(state.question&&!state.question.accepted_answer&&state.selected)state.pending={action:'answer',session_id:state.session,question_id:state.question.question_id,answer:state.selected};
  savePending();render();tick();
}
for(const key of keys)key.addEventListener('click',()=>digit(key.dataset.key));
$('confirm').addEventListener('click',confirm);
$('clear').addEventListener('click',()=>{state.input='';state.message='';render();});
document.addEventListener('keydown',event=>{if(event.repeat)return;if(/^[0-9]$/.test(event.key)){event.preventDefault();digit(event.key);}else if(event.key==='Enter'){event.preventDefault();confirm();}else if(event.key==='Backspace'&&!state.student&&!state.pending){event.preventDefault();state.input=state.input.slice(0,-1);render();}});
window.addEventListener('online',tick);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)tick();});
async function initialize(){
  try{
    const response=await fetch('phone-api.php');const identity=await response.json();
    if(!response.ok||!identity.ok)throw new Error('Không cấp được mã thiết bị.');
    state.uid=identity.device_uid;state.csrf=identity.csrf;$('device-uid').textContent=state.uid;
    try{state.pending=JSON.parse(localStorage.getItem(storageKey())||'null');}catch{state.pending=null;}
    await tick();
  }catch(error){state.message=error.message;render();setTimeout(initialize,2000);}
}
render();initialize();setInterval(tick,1000);
