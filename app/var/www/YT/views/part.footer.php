<?php if (!isset($GLOBALS['config'])) { die("No direct script access"); } ?>
<?php 
$yt_dlp_version = @file_get_contents('yt_dlp_version');
$yt_dlp_version = ($yt_dlp_version !== false) ? trim($yt_dlp_version) : 'yt-dlp: Ошибка';
?>
    <footer class="footer" style="max-height: 90%; overflow-y: auto; position: fixed; bottom: 0%; left: 50%; transform: translate(-50%); text-align: center; width:99.5%;">
            <div class="text-center" style="padding: 0;margin: 0; border-top-left-radius:80px 80px; border-top-right-radius:80px 80px;">

    <div class="panel panel-info" style="margin: 0;border-top-left-radius: 50px 60px;border-top-right-radius:50px 60px">
      <div onclick="helpPanel()" style="cursor: pointer;" class="panel-heading">
        <h3 id="helplink" class="panel-title">Жмак</h3>
      </div>
      <div id="helppanel" class="panel-body panel-collapsed" style="display: none;">
        <table class="table table-striped table-hover" style="text-align: left;">
          <tr>
            <td><b>О версии:</b></td>
            <td>
                C обходом замедления YouTube!<br>
                <div id="yt-dlp-version" class="text-muted small">yt-dlp: <?php echo htmlspecialchars($yt_dlp_version, ENT_QUOTES, 'UTF-8'); ?></div>
            </td>
          </tr>
          <!--<tr>
            <td><b>Свободно места на сервере:</b></td>
            <td><?php echo htmlspecialchars($file->free_space(), ENT_QUOTES, 'UTF-8'); ?>B</td>
          </tr>
          <tr>
            <td><b>Папка Загрузки:</b></td>
            <td><?php echo htmlspecialchars($file->get_downloads_folder(), ENT_QUOTES, 'UTF-8'); ?></td>
          </tr>-->
          <tr>
            <td><b>Как это работает?</b></td>
            <td>
              <ul style="margin:0; padding-left:20px; list-style-position:inside;">
                <li>Вставьте ссылку в поле "Скачать"</li>
                <li>Во вкладке "Видео" находятся готовые файлы</li>
                <li>Во вкладке "Загрузки" можно увидеть историю загрузок и их статус</li>
              </ul>
              <small>Загрузка файлов списком: <code>vk.com/xx1||vk.com/xx2||vk.com/xx3</code></small>
            </td>
          </tr>
          <tr>
            <td><b>Как забрать файлы с сервера?</b></td>
            <td>
               Нажать на <a href="Видео" onclick="$('#vid_link').click()" data-toggle="tab" aria-expanded="false">файл</a> или открыть в новой вкладке
            </td>
          </tr>
          <tr>
            <td><b>Ошибка?</b></td>
            <td>
              Проверить корректность URL<br>
              Ссылка должна быть прямой и указывать на видео или плейлист<br>
              Файл уже скачан - <a href="Загрузки" onclick="$('#dl_link').click()" data-toggle="tab" aria-expanded="false">проверь историю</a> или <a href="Видео" onclick="$('#vid_link').click()" data-toggle="tab" aria-expanded="false">имя файла</a><br>
              "В процессе" - файл конвертируется в mp4<br>
              Бэкенд еще не поддерживает ваш ресурс
            </td>
          </tr>
          <tr>
            <td><b>Полезные ссылки</b></td>
            <td>
              <a target="_blank" rel="noopener noreferrer" href="https://ezgif.com/video-to-gif">Видео в гиф</a> &nbsp;&nbsp;
              <a target="_blank" href="https://github.com/mikedigriz/YT">GitHub</a>
            </td>
          </tr>
        </table>
      </div>
    </div>
            </div>
        </footer>
    </body>
</html>