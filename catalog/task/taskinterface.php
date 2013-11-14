<?php

namespace Catalog\Task;

use Catalog\Application;

interface TaskInterface
{
  public function run(Application $application);
}