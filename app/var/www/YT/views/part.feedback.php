<?php if (!isset($GLOBALS['config'])) { die("No direct script access"); }

$fbEsc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$fbDate = fn(int $ts) => $ts > 0 ? date('d.m.Y H:i', $ts) : '';
$fbToken = generateCsrfToken();
$fbDraftTitle = (string) ($feedbackDraft['title'] ?? '');
$fbDraftMessage = (string) ($feedbackDraft['message'] ?? '');
?>
<main class="fb-page" id="fb-page">
  <nav class="fb-crumbs" aria-label="Навигация">
    <a href="index.php">← Качалка</a>
    <?php if (!empty($feedbackDialog)): ?>
      <span aria-hidden="true">/</span>
      <a href="index.php?feedback">Все обращения</a>
    <?php endif; ?>
  </nav>

  <h1 class="fb-title">
    <?= !empty($feedbackDialog)
        ? '#' . (int) $feedbackDialog['id'] . ' ' . $fbEsc($feedbackDialog['title'])
        : 'Обратная связь' ?>
  </h1>

  <?php if (!empty($feedbackFlash)): ?>
    <?php
      // role="alert" у ошибки, role="status" у успеха: разная срочность объявления
      // скринридером - сообщение об ошибке перебивает чтение, подтверждение ждёт паузы.
      $fbAlertOk = !empty($feedbackFlash['ok']);
      $fbAlertClass = $fbAlertOk ? 'fb-alert fb-alert-ok' : (!empty($feedbackFlash['limited']) ? 'fb-alert fb-alert-wait' : 'fb-alert fb-alert-error');
    ?>
    <div class="<?= $fbAlertClass ?>" role="<?= $fbAlertOk ? 'status' : 'alert' ?>" tabindex="-1" id="fb-flash">
      <?= $fbEsc($feedbackFlash['text']) ?>
    </div>
  <?php endif; ?>

<?php if (empty($feedbackDialog)): ?>

  <?php if ($feedbackId > 0): ?>
    <p class="fb-empty">Обращения с таким номером нет. Возможно, ошибка в ссылке.</p>
  <?php endif; ?>

  <p class="fb-lead">
    Нашёл ошибку или есть идея - напиши. Входа тут нет, всё анонимно и видно всем.
    Ответить в чужое обращение тоже может любой.
  </p>

  <div class="fb-warn" role="note">
    <strong>Не отправляй пароли, токены, куки и личные данные.</strong>
    Страница публичная: написанное увидит каждый, кто её откроет, и удалить это нельзя.
  </div>

  <section class="fb-form-section" aria-labelledby="fb-new-heading">
    <h2 id="fb-new-heading">Новое обращение</h2>
    <form method="post" action="index.php?feedback" class="fb-form" id="fb-form-new" novalidate>
      <input type="hidden" name="csrf_token" value="<?= $fbEsc($fbToken) ?>">
      <input type="hidden" name="feedback_new" value="1">

      <div class="fb-field">
        <label for="fb-title">Заголовок</label>
        <input type="text" id="fb-title" name="title" maxlength="<?= Feedback::MAX_TITLE ?>"
               required autocomplete="off" aria-describedby="fb-title-hint"
               value="<?= $fbEsc($fbDraftTitle) ?>">
        <p class="fb-hint" id="fb-title-hint">
          Коротко о сути. До <?= Feedback::MAX_TITLE ?> символов.
          <span class="fb-counter" data-counter-for="fb-title" aria-hidden="true"></span>
        </p>
      </div>

      <div class="fb-field">
        <label for="fb-message">Сообщение</label>
        <textarea id="fb-message" name="message" rows="8" required
                  maxlength="<?= Feedback::MAX_BODY ?>" aria-describedby="fb-message-hint"><?= $fbEsc($fbDraftMessage) ?></textarea>
        <p class="fb-hint" id="fb-message-hint">
          Работает разметка Markdown: заголовки, списки, цитаты, таблицы и блоки кода
          через <code>```</code>. HTML и картинки вставить нельзя.
          <span class="fb-counter" data-counter-for="fb-message" aria-hidden="true"></span>
        </p>
      </div>

      <?php /* Приманка: человек этого поля не видит и не достанет табом. Заполнено -
               отправка тихо отбрасывается (см. обработчик в index.php). */ ?>
      <div class="fb-trap" aria-hidden="true">
        <label for="fb-website">Не заполняй это поле</label>
        <input type="text" id="fb-website" name="website" tabindex="-1" autocomplete="off">
      </div>

      <?php if (!empty($feedbackAdminAvailable)): ?>
        <?php /* Обращение от администратора - объявления и заметки на доске.
                 Права проверяются на сервере, галочка сама по себе ничего не даёт. */ ?>
        <div class="fb-field fb-admin-reply">
          <label class="fb-checkbox">
            <input type="checkbox" name="as_admin" value="1" id="fb-new-as-admin">
            Написать от имени администратора
          </label>
          <?php if (empty($feedbackAdminTrusted)): ?>
            <label for="fb-new-pass">Пароль администратора</label>
            <input type="password" id="fb-new-pass" name="admin_password" autocomplete="current-password">
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <button type="submit" class="fb-submit">Отправить</button>
    </form>
  </section>

  <section class="fb-list-section" aria-labelledby="fb-list-heading">
    <h2 id="fb-list-heading">Обращения<?= !empty($feedbackList['total']) ? ' (' . (int) $feedbackList['total'] . ')' : '' ?></h2>

    <?php if (empty($feedbackList['items'])): ?>
      <p class="fb-empty">Пока пусто. Твоё обращение будет первым.</p>
    <?php else: ?>
      <ul class="fb-list">
        <?php foreach ($feedbackList['items'] as $item): ?>
          <li class="fb-list-item<?= $item['count'] < 2 ? ' fb-list-item-open' : '' ?>">
            <a href="index.php?feedback=<?= (int) $item['id'] ?>">
              <span class="fb-list-num">#<?= (int) $item['id'] ?></span>
              <span class="fb-list-title"><?= $fbEsc($item['title']) ?></span>
            </a>
            <span class="fb-list-meta">
              <?= (int) $item['count'] ?> <?= $item['count'] === 1 ? 'сообщение' : 'сообщ.' ?>
              · <?= $fbEsc($fbDate((int) $item['updated'])) ?>
              <?php if ($item['count'] < 2): ?>
                <span class="fb-badge-open">без ответа</span>
              <?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>

      <?php if (($feedbackList['pages'] ?? 1) > 1): ?>
        <nav class="fb-pager" aria-label="Страницы обращений">
          <?php if ($feedbackList['page'] > 1): ?>
            <a href="index.php?feedback&amp;page=<?= $feedbackList['page'] - 1 ?>" rel="prev">← Новее</a>
          <?php endif; ?>
          <span>Страница <?= (int) $feedbackList['page'] ?> из <?= (int) $feedbackList['pages'] ?></span>
          <?php if ($feedbackList['page'] < $feedbackList['pages']): ?>
            <a href="index.php?feedback&amp;page=<?= $feedbackList['page'] + 1 ?>" rel="next">Старее →</a>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>

<?php else: ?>

  <p class="fb-dialog-meta">
    Заведено <?= $fbEsc($fbDate((int) $feedbackDialog['created'])) ?>,
    сообщений: <?= count($feedbackDialog['messages']) ?>
  </p>

  <ol class="fb-messages">
    <?php foreach ($feedbackDialog['messages'] as $msg): $fbIsAdminMsg = !empty($msg['admin']); ?>
      <li class="fb-message<?= $fbIsAdminMsg ? ' fb-message-admin' : '' ?>" id="msg-<?= (int) $msg['n'] ?>">
        <header class="fb-message-head">
          <a class="fb-message-num" href="#msg-<?= (int) $msg['n'] ?>">#<?= (int) $msg['n'] ?></a>
          <?php if ($fbIsAdminMsg): ?>
            <span class="fb-admin-tag">Администратор</span>
          <?php endif; ?>
          <time datetime="<?= $fbEsc(date('c', (int) $msg['ts'])) ?>"><?= $fbEsc($fbDate((int) $msg['ts'])) ?></time>
        </header>
        <div class="fb-message-body md-body">
          <?php /* Единственное место вывода пользовательского текста как HTML.
                   Markdown::render() экранирует ввод ДО разбора, поэтому сырых тегов
                   тут появиться не может - подробности в самом классе. */ ?>
          <?= Markdown::render((string) $msg['body']) ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ol>

  <?php if (!empty($feedbackAdminAvailable)): ?>
    <?php /* Панель видна всем, но нажатие без прав ничего не даёт: из доверенной
             сети (feedbackAdminNetworks) кнопки работают сразу, снаружи каждое
             действие требует пароль - метки в сессии нет намеренно, чтобы
             угнанная кука не давала прав. */ ?>
    <details class="fb-admin"<?= !empty($feedbackAdminTrusted) ? ' open' : '' ?>>
      <summary>Управление обращением</summary>

      <form method="post" action="index.php?feedback=<?= (int) $feedbackDialog['id'] ?>" class="fb-admin-form" id="fb-admin-form">
        <input type="hidden" name="csrf_token" value="<?= $fbEsc($fbToken) ?>">
        <input type="hidden" name="dialog" value="<?= (int) $feedbackDialog['id'] ?>">

        <?php if (empty($feedbackAdminTrusted)): ?>
          <div class="fb-field">
            <label for="fb-admin-pass">Пароль администратора</label>
            <input type="password" id="fb-admin-pass" name="admin_password"
                   autocomplete="current-password" aria-describedby="fb-admin-pass-hint">
            <p class="fb-hint" id="fb-admin-pass-hint">Запрашивается на каждое действие.</p>
          </div>
        <?php else: ?>
          <p class="fb-hint">Адрес доверенный - пароль не нужен.</p>
        <?php endif; ?>

        <p class="fb-admin-label" id="fb-admin-del-label">Удалить сообщение:</p>
        <div class="fb-admin-buttons" role="group" aria-labelledby="fb-admin-del-label">
          <?php foreach ($feedbackDialog['messages'] as $msg): ?>
            <button type="submit" name="feedback_delete_message" value="<?= (int) $msg['n'] ?>"
                    class="fb-admin-btn" data-confirm="Удалить сообщение #<?= (int) $msg['n'] ?>? Текст сотрётся с диска, остальные перенумеруются."
                    aria-label="Удалить сообщение номер <?= (int) $msg['n'] ?>">#<?= (int) $msg['n'] ?></button>
          <?php endforeach; ?>
        </div>

        <button type="submit" name="feedback_delete_dialog" value="1" class="fb-admin-btn fb-admin-btn-danger"
                data-confirm="Удалить обращение #<?= (int) $feedbackDialog['id'] ?> целиком? Отменить это нельзя.">
          Удалить обращение целиком
        </button>
      </form>
    </details>
  <?php endif; ?>

  <?php $fbFull = count($feedbackDialog['messages']) >= (int) ($config['feedbackMaxPerDialog'] ?? 200); ?>

  <section class="fb-form-section" aria-labelledby="fb-reply-heading" id="end">
    <h2 id="fb-reply-heading">Ответить</h2>

    <?php if ($fbFull): ?>
      <p class="fb-empty">В этом обращении набралось предельное число сообщений. Заведи новое.</p>
    <?php else: ?>
      <div class="fb-warn" role="note">
        <strong>Не отправляй пароли, токены, куки и личные данные.</strong>
        Страница публичная, удалить написанное нельзя.
      </div>

      <form method="post" action="index.php?feedback=<?= (int) $feedbackDialog['id'] ?>" class="fb-form" id="fb-form-reply" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $fbEsc($fbToken) ?>">
        <input type="hidden" name="feedback_reply" value="1">
        <input type="hidden" name="dialog" value="<?= (int) $feedbackDialog['id'] ?>">

        <div class="fb-field">
          <label for="fb-reply">Сообщение</label>
          <textarea id="fb-reply" name="message" rows="6" required
                    maxlength="<?= Feedback::MAX_BODY ?>" aria-describedby="fb-reply-hint"><?= $fbEsc($fbDraftMessage) ?></textarea>
          <p class="fb-hint" id="fb-reply-hint">
            Работает разметка Markdown. HTML и картинки вставить нельзя.
            <span class="fb-counter" data-counter-for="fb-reply" aria-hidden="true"></span>
          </p>
        </div>

        <div class="fb-trap" aria-hidden="true">
          <label for="fb-website-reply">Не заполняй это поле</label>
          <input type="text" id="fb-website-reply" name="website" tabindex="-1" autocomplete="off">
        </div>

        <?php if (!empty($feedbackAdminAvailable)): ?>
          <?php /* Галочка сама по себе прав не даёт: as_admin проверяется в
                   Feedback::addMessage() - доверенная сеть либо пароль. */ ?>
          <div class="fb-field fb-admin-reply">
            <label class="fb-checkbox">
              <input type="checkbox" name="as_admin" value="1" id="fb-as-admin">
              Ответить от имени администратора
            </label>
            <?php if (empty($feedbackAdminTrusted)): ?>
              <label for="fb-reply-pass">Пароль администратора</label>
              <input type="password" id="fb-reply-pass" name="admin_password" autocomplete="current-password">
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <button type="submit" class="fb-submit">Отправить</button>
      </form>
    <?php endif; ?>
  </section>

<?php endif; ?>
</main>
