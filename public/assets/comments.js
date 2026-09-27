(() => {
  const dialog=document.getElementById('comment-reply-dialog');
  if(!dialog) return;
  document.querySelectorAll('[data-reply-comment]').forEach(button=>button.addEventListener('click',()=>{
    document.getElementById('reply-parent').value=button.dataset.replyComment;
    document.getElementById('reply-post').value=button.dataset.replyPost;
    dialog.showModal();dialog.querySelector('textarea').focus();
  }));
  document.getElementById('close-comment-reply').addEventListener('click',()=>dialog.close());
  document.getElementById('comment-actions').addEventListener('submit',event=>{
    if(event.submitter?.value?.startsWith('delete:') || (event.submitter?.textContent.trim()==='Apply' && event.currentTarget.elements.action.value==='delete')) {
      if(!confirm('Permanently delete selected comments?')) event.preventDefault();
    }
  });
})();
