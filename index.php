<?php
/**
 * Plugin Name: Bitcoin-Mastermind
 * Description: Add-on for HD Quiz that sends bitcoin rewards over the Lightning Network for correct quiz answers.
 * Plugin URI: github link to follow
 * Author: ealvar13
 * License: GPL-2.0+
 * Author URI: https://github.com/ealvar13
 * Version: 0.1.0
 */

require dirname(__FILE__) . '/hd-quiz/index.php';
require dirname(__FILE__) . '/hd-quiz-bitcoin-rewards/index.php';
require dirname(__FILE__) . '/hd-quiz-save-results-light/index.php';