<?php if (!isset($GLOBALS['config'])) { die("No direct script access");
} ?>
<div class="container" style="margin-bottom: 50px;">
  <ul id="mainnav" class="nav nav-tabs ">
    <li class="active"><a id="home_link" href="#home" data-toggle="tab"  aria-expanded="true">Домой</a></li>
    <li><a id="dl_link" href="#downloads" data-toggle="tab" aria-expanded="false">Загрузки</a></li>
    <li><a id="vid_link" href="#videos" data-toggle="tab" aria-expanded="false">Видео</a></li>
    <li><a id="music_link" href="#music" data-toggle="tab" aria-expanded="false">Музыка</a></li>
  </ul>
  <div id="myTabContent" class="tab-content">
    <div class="tab-pane fade active in" id="home">
<div class="wow pulse" id="snej" data-wow-offset="2"  data-wow-iteration="1" style="pointer-events: none">
  <input type="image" src="img/snej2.png" title="Снежик">
</div>
<!--<div class="wow zoomInRight" id="cat" data-wow-duration="2s" data-wow-delay="0.2s" style="pointer-events: none">
  <input type="image" src="img/cat.gif" title="Кися">
</div>
-->
      <div class="row">
        <br />
        <h1 style="text-align: center;"><?php echo($config['siteName']); ?></h1><br />
<?php if(isset($_SESSION['errors']) && $_SESSION['errors'] > 0) : ?>
    <?php foreach ($_SESSION['errors'] as $e): ?>
    <div class="alert alert-warning" role="alert"><?php echo ($e); ?></div>
    <?php endforeach; ?>
<?php endif; ?>
        <form id="download-form" class="form-horizontal" action="index.php" method="post">
          <div class="form-group">
            <div class="col-md-12">
              <input class="form-control wow flipInX" id="url" data-wow-iteration="1" data-wow-delay="0.6s" name="urls"<?php echo($urlvalue); ?> placeholder="Вставь URL: https://youtu.be/VDEtP9woMN8" type="text">
            </div>
            <div class="col-md-12">
              <div style="text-align: center;" class="checkbox">
                <button style="width: 90px;" type="submit" class="btn btn-primary">Скачать</button><br />
                <label<?php echo($config['disableExtraction'] ? " style=\"display: none;\"" : ""); ?>>
                  <input id="audio_convert" onclick="checkControls();"<?php echo($audio_check); ?>  type="checkbox" name="audio"> Конвертировать в Аудио
                </label>
                <label id="audio_group"<?php echo($audio_form_style); ?>>
                  Формат Аудио:
                  <select style="width: 75px;" name="audio_format" id="audio_format">
                    <option value="mp3-high"<?php echo($_GET["audio_format"]=="mp3-high" ? " selected=\"selected\"" : ""); ?>>mp3 HQ</option>
                    <option value="mp3"<?php echo($_GET["audio_format"]=="mp3" ? " selected=\"selected\"" : ""); ?>>mp3</option>
                    <option value="wav"<?php echo($_GET["audio_format"]=="wav" ? " selected=\"selected\"" : ""); ?>>wav</option>
                  </select>
                </label>
                <label id="video_group"<?php echo($video_form_style); ?>>
                  Качество Видео:
                  <select style="width: 75px;" name="format" id="format">
                    <option value="-S res:1080 -f 'bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4] / bv*+ba/b'"<?php echo($_GET["format"]=="best" ? " selected=\"selected\"" : ""); ?>>Топ</option>
                    <option value="worst"<?php echo($_GET["format"]=="worst" ? " selected=\"selected\"" : ""); ?>>Булшит</option>
                  </select>
                </label>
              </div>
            </div>
          </div>
        </form>
      </div>
    </div>
    <div class="tab-pane fade" id="downloads">
      <div style="text-align: center;" class="row">
        <br /><br />
        <h4>Текущие Загрузки</h4>
        <table style="text-align: left;" class="table table-striped table-hover ">
          <thead>
            <tr>
              <th style="width: 10%; height:35px;">Сайт/Тип</th>
              <th>Файл</th>
              <th style="width: 25%;">Статус</th>
              <th style="width: 120px;">Действия</th>
            </tr>
          </thead>
          <tbody id="dlprogress">
            <tr>
              <td colspan="4">Буп-Буп понеслась! Жди...</td>
            </tr>
          </tbody>
        </table>
        <br /><br />
<?php if(!$config['disableQueue']) : ?>
        <h4>Очередь</h4>
        <table style="text-align: left;" class="table table-striped table-hover ">
          <thead>
            <tr>
              <th style="height:35px;">URL</th>
              <th style="width: 120px;">Формат</th>
              <th style="width: 120px;">Действия</th>
            </tr>
          </thead>
          <tbody id="dlqueue">
            <tr>
              <td colspan="3">Добавляю в очередь ждемс...</td>
            </tr>
          </tbody>
        </table>
        <br /><br />
<?php endif; ?>
        <h4>Последние Загрузки</h4>
        <table style="text-align: left;" class="table table-striped table-hover ">
          <thead>
            <tr>
              <th style="width: 10%; height:35px;">Сайт/Тип</th>
              <th>Файл/Плейлист</th>
              <th style="width: 25%;">Статус</th>
              <th style="width: 180px;">Действия</th>
            </tr>
          </thead>
          <tbody id="dlcompleted">
            <tr>
              <td colspan="4">Получаю загрузки...</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="tab-pane fade" id="videos">
      <br /><br />
      <h4 style="text-align: center;">Загруженные Видео</h4>
      <table style="text-align: left;" class="table table-striped table-hover ">
        <thead>
          <tr>
            <th style="min-width:800px; height:35px">Файл</th>
            <th style="min-width:80px">Размер</th>
            <?php if ($config['allowFileDelete']) : ?>
              <th style="min-width:110px">Действия</th>
            <?php else: ?>
              <th></th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody id="videofiles">
          <tr>
            <td colspan="3">Получаю видео, обажди...</td>
          </tr>
        </tbody>
      </table>
      <br/>
      <br/>
    </div>
    <div class="tab-pane fade" id="music">
      <br /><br />
      <h4 style="text-align: center;">Загруженные Аудио</h4>
      <table style="text-align: left;" class="table table-striped table-hover ">
        <thead>
          <tr>
            <th style="min-width:800px; height:35px">Файл</th>
            <th style="min-width:80px">Размер</th>
            <?php if ($config['allowFileDelete']) : ?>
              <th style="min-width:110px">Действия</th>
            <?php else: ?>
              <th></th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody id="musicfiles">
          <tr>
            <td colspan="3">Получаю аудио, ждемс...</td>
          </tr>
        </tbody>
      </table>
      <br/>
      <br/>
    </div>
  </div>
</div>
<script>
  $('#mainnav a').click(function(e) {
    e.preventDefault();
    var id = $(e.target).attr("href").substr(1);
    window.location.hash = id;
    $(this).tab('show');
  });

  var hash = window.location.hash;
  $('#mainnav a[href="' + hash + '"]').tab('show');
</script>
