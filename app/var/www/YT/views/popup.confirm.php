<?php if (!isset($GLOBALS['config'])) { die("No direct script access");
} ?>
<div class="modal fade" id="confirm-delete" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
  <div class="modal-dialog" style="z-index: 10000;">
    <div class="modal-content">
      <div class="modal-header">
        <button class="close" type="button" data-dismiss="modal" aria-hidden="true">×</button>
        <h4 class="modal-title">Защита от 'дурака'</h4>
      </div>
      <div class="modal-body">
        Это действие не отменить!<br /><br />Ты уверен?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Ой, стоп!</button>
        <a class="btn btn-danger btn-ok">Да, я понимаю</a>
      </div>
    </div>
  </div>
</div>
<script>
  $('#confirm-delete').on('show.bs.modal', function(e) {
    $(this).find('.btn-ok').attr('href', $(e.relatedTarget).data('href'));
  });
</script>
