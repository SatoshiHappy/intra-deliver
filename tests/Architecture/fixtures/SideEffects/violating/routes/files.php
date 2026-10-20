<?php

$byExpression = new (\SplFileObject::class)('/tmp/report.txt', 'w');

$byAnonymousClass = new class('/tmp/report.txt', 'w') extends \SplFileObject {};
