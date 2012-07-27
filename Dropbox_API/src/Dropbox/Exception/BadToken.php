<?php

/**
 * Dropbox BadToken exception
 *
 * @package Dropbox
 * @copyright Copyright (C) 2010 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/)
 * @license http://code.google.com/p/dropbox-php/wiki/License MIT
 */

/**
 * This exception is thrown when we receive the 403 bad token response
 */
class Dropbox_Exception_BadToken extends Dropbox_Exception {}