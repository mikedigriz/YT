<?php if (!isset($GLOBALS['config'])) { die("No direct script access");
} ?>
<div class="modal fade" id="custom_bookmarklet" tabindex="-1" role="dialog" aria-labelledby="myModalLabel2" aria-hidden="true">
  <div class="modal-dialog" style="z-index: 10000;">
    <div class="modal-content">
      <div class="modal-header">
        <button class="close" type="button" data-dismiss="modal" aria-hidden="true">×</button>
        <h4 class="modal-title">Custom Bookmarklet</h4>
      </div>
      <div class="modal-body">
        <input id="bml_base_uri" type="hidden" value="<?php echo($baseuri); ?>" />
        Select the options below and then drag the button to your bookmarks toolbar.<br /><br />
        <label<?php echo($config['disableExtraction'] ? " style=\"display: none;\"" : ""); ?>>
          <input id="bml_audio_convert" onclick="updateBookmarklet();" type="checkbox" name="bml_audio"> Convert to Audio
        </label><br />
        <label id="bml_audio_group" style="display: none;">
          Audio Format:
          <select style="width: 75px;" name="bml_audio_format" id="bml_audio_format" onchange="updateBookmarklet();">
            <option value="mp3-high" selected="selected">mp3-high</option>
            <option value="mp3" selected="selected">mp3</option>
            <option value="aac">aac</option>
            <option value="vorbis">vorbis</option>
            <option value="m4a">m4a</option>
            <option value="opus">opus</option>
            <option value="wav">wav</option>
          </select>
        </label>
        <label id="bml_video_group">
          Video Quality:
          <select style="width: 75px;" name="bml_format" id="bml_format" onchange="updateBookmarklet();">
            <option value="best" selected="selected">Best</option>
            <option value="worst">Smallest</option>
          </select>
        </label>
        <br />
        <label>
          <input id="bml_auto_submit" onclick="updateBookmarklet();" type="checkbox" name="bml_auto_submit"> Start download immediately
        </label>
        <br />
        <label>
          <input id="bml_use_custom_text" onclick="updateBookmarklet();" type="checkbox" name="bml_use_custom_text"> Use a custom text for the bookmarklet:&nbsp;
        </label>
        <input id="bml_custom_text" onkeyup="updateBookmarklet(true);" type="text" name="bml_custom_text" value="Download Video" />
        <br />Drag the below button to your bookmarks toolbar.<br />
        <a id="cust_bml" href="<?php echo($bookmarkletvideo); ?>" style="width: 300px;" class="btn btn-primary">Download Video</a>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Done</button>
      </div>
    </div>
  </div>
</div>
