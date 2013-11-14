<!DOCTYPE html>
<html>
<head>
  <title>Каталог</title>
  <script src="http://yandex.st/jquery/2.0.3/jquery.min.js"></script>
  <script src="/static/catalog.js"></script>
  <style type="text/css">
    #tooltip {
      background-color: #ffbd4a;
      position: absolute;
      z-index: 1000000;
      width: 250px;
      border-radius: 5px;
      display: none;
    }
    #tooltip:before {
      border-color: transparent #3D6199 transparent transparent;
      border-right: 6px solid #ffbd4a;
      border-style: solid;
      border-width: 6px 6px 6px 0px;
      content: "";
      display: block;
      height: 0;
      width: 0;
      line-height: 0;
      position: absolute;
      top: 40%;
      left: -6px;
    }
    #tooltip p {
      margin: 10px;
      text-align: center;
    }
    #tooltip a {
      margin-left:10px
    }
  </style>
</head>
<body>
  <?php if($attributes): ?>
  <form method="get" id="filter">
    <div id="tooltip"><p>Выбрано моделей: <span></span><a href="#">Показать</a></p></div>
    <?php foreach($attributes as $name => $options): ?>
    <div>
      <div class="title"><?php echo $name ?>:</div>
      <?php foreach($options as $option): ?>
      <input type="checkbox" name="<?php echo $option['a_id'] ?>" value="<?php echo $option['o_id'] ?>" /><span><?php echo $option['o_name'] ?></span>
      <?php endforeach ?>
    </div>
    <?php endforeach ?>
    <div>
      <input type="submit" value="Искать" />
    </div>
  </form>
  <?php endif ?>
  <ol id="results"></ol>
</body>
</html>