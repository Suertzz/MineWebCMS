<section class="content">
  <div class="row">
    <div class="col-md-12">
      <div class="box">
        <div class="box-header with-border">
          <h3 class="box-title"><?= $Lang->get('EDIT_NEWS') ?></h3>
        </div>
        <div class="box-body">
          <form action="<?= $this->Html->url(array('controller' => 'news', 'action' => 'edit_ajax')) ?>" method="post">
            <input type="hidden" id="form_infos" data-ajax="true" data-redirect-url="<?= $this->Html->url(array('controller' => 'news', 'action' => 'admin_index', 'admin' => 'true')) ?>">

            <div class="ajax-msg"></div>
            
            <input type="hidden" name="id" value="<?= $news['id'] ?>">
      
            <div class="form-group">
              <label><?= $Lang->get('TITLE') ?></label>
              <input name="title" class="form-control" value="<?= $news['title'] ?>" placeholder="<?= $Lang->get('TITLE') ?>" type="text">
            </div>

            <div class="form-group">
              <label><?= $Lang->get('SLUG') ?></label>
              <div class="input-group">
                <input name="slug" id="slug" class="form-control" value="<?= $news['slug'] ?>" placeholder="<?= $Lang->get('SLUG') ?>" type="text">
                <span class="input-group-btn">
                  <a href="#" id="generate_slug" class="btn btn-info"><?= $Lang->get('GENERATE') ?></a>
                </span>
              </div>
            </div>

            <div class="form-group">
              <?= $this->Html->script('admin/tinymce/tinymce.min.js') ?>
              <script type="text/javascript">
              tinymce.init({
                  selector: "textarea",
                  height : 300,
                  width : '100%',
                  language : 'fr_FR',
                  plugins: "textcolor code image link",
                  toolbar: "fontselect fontsizeselect bold italic underline strikethrough link image forecolor backcolor alignleft aligncenter alignright alignjustify cut copy paste bullist numlist outdent indent blockquote code"
               });
              </script>
              <textarea id="editor" name="content" cols="30" rows="10"><?= $news['content'] ?></textarea>
            </div>

            <div class="form-group">
              <div class="checkbox">
                <label>
                  <?php 
                    if($news['published']) {
                      $checked = true;
                    } else {
                      $checked = false;
                    }
                  ?>
                  <input name="published" type="checkbox"<?= ($news['published']) ? ' checked=""' : ''; ?>> <?= $Lang->get('PUBLISH_THIS_NEWS') ?>
                </label>
              </div>
            </div>

            <div class="pull-right">
              <a href="<?= $this->Html->url(array('controller' => 'news', 'action' => 'admin_index', 'admin' => true)) ?>" class="btn btn-default"><?= $Lang->get('CANCEL') ?></a>  
              <button class="btn btn-primary" type="submit"><?= $Lang->get('SUBMIT') ?></button>
            </div>
          </form>      
        </div>
      </div>
    </div>
  </div>
</section>