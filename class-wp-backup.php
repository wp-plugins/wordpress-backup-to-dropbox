<?php
/**
 * A class with functions the perform a backup of WordPress
 *
 * @copyright Copyright (C) 2011 Michael De Wildt. All rights reserved.
 * @author Michael De Wildt (http://www.mikeyd.com.au/)
 * @license This program is free software; you can redistribute it and/or modify
 *          it under the terms of the GNU General Public License as published by
 *          the Free Software Foundation; either version 2 of the License, or
 *          (at your option) any later version.
 *
 *          This program is distributed in the hope that it will be useful,
 *          but WITHOUT ANY WARRANTY; without even the implied warranty of
 *          MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *          GNU General Public License for more details.
 *
 *          You should have received a copy of the GNU General Public License
 *          along with this program; if not, write to the Free Software
 *          Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110, USA.
 */
class WP_Backup {

	const BACKUP_STATUS_STARTED = 0;
	const BACKUP_STATUS_SUCCESS = 1;
	const BACKUP_STATUS_ERROR = 2;
    const BACKUP_STATUS_UPLOADING = 3;

    /**
     * The users Dropbox options
     * @var object
     */
    private $options = null;

    /**
     * The users backup schedule
     * @var object
     */
    private $schedule = null;

    /**
     * The users backup history
     * @var object
     */
    private $history = null;

    /**
     * Construct the Backup class and pre load the schedule, history and options
     * @return void
     */
    public function __construct() {
        //Load the history
        $this->history = get_option( 'backup-to-dropbox-history' );
        if ( !$this->history ) {
            add_option( 'backup-to-dropbox-history', array(), null, 'no' );
            $this->history = array();
        }
        krsort( $this->history );

        //Load the options
        $this->options = get_option( 'backup-to-dropbox-options' );
        if ( !$this->options ) {
            //Options: Local backup location, Dropbox backup location, Keep local backups, Max backups to keep
            $this->options = array( 'wp-content/backups', 'WordPressBackup', true, 6 );
            add_option( 'backup-to-dropbox-options', $this->options, null, 'no' );
        }

        //Load the schedule
        $time = wp_next_scheduled( 'execute_periodic_drobox_backup' );
		$frequency = wp_get_schedule( 'execute_periodic_drobox_backup' );
        if ( $time && $frequency ) {
			//Convert the time to the blogs timezone
			$blog_time = strtotime( current_time( 'mysql' ) ) + ( $time - time() );
			$this->schedule = array( $blog_time, $frequency );
        }
    }

    /**
     * Zips up all the files within this wordpress installation, compresses them and then saves the compressed
     * archive on the server.
     * Original Source: http://stackoverflow.com/questions/1334613/how-to-recursively-zip-a-directory-in-php
     * @return string - Path to the database dump
     */
    public function backup_website( $destination ) {
        list( $dump_location, , , ) = $this->get_options();
        $exclude = ABSPATH . $dump_location . '/wordpress-backup';
        if ( PHP_OS == 'WINNT' ) {
            $exclude = str_replace( '/', '\\', $exclude );
        }

        //Grab the memory limit setting in the php ini to ensure we do not exceed it
        $memory_limit_string = ini_get( 'memory_limit' );
        $memory_limit = ( preg_replace( '/\D/', '', $memory_limit_string ) * 1048576 );
        $close_limit = $memory_limit / 3;
        $max_file_size = $memory_limit / 2;
        
		$zip = null;
        if ( file_exists( ABSPATH ) ) {
            $source = realpath( ABSPATH );
            if ( is_dir( $source ) ) {
                $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source ), RecursiveIteratorIterator::SELF_FIRST );
                foreach ( $files as $file ) {
                    $file = realpath( $file );
                    if ( !strstr( $file, $exclude ) ) {
                        //We need to check that the file size the we are trying to zip does not exceed half the available memory.
                        //If it does then this code attempts to increase the php memory limit ini setting. If we cannot increase
                        //the limit due to hosting constraints then there is nothing that can be done and the user will need to
                        //either get their memory allowance increased or remove the offending file.
                        $file_size = filesize( $file );
                        if ( $file_size > $max_file_size ) {
                            $new_limit = round( ( $file_size / 1048576 ) * 2.5 );
                            if ( ini_set( 'memory_limit', $new_limit . 'M') ) {
                                $close_limit = ( $new_limit * 1048576 ) / 3;
                            } else {
                                throw new Exception( __( 'Memory limit error adding a file to the zip archive. The plugin attempted to increase the memory limit automatically but failed due to server restrictions.' ) );
                            }
                        }
                        
                        //Open the zip archive and add a file
						if ( !$zip ) {
							$zip = new ZipArchive();
							if ( !$zip->open( $destination, ZipArchive::CREATE ) ) {
								throw new Exception( __( 'error opening zip archive.' ) . ' (ERROR_1)' );
							}
						}

                        if ( is_dir( $file ) ) {
                            $zip->addEmptyDir( str_replace( $source . '/', '', $file . '/' ) );
                        } else if ( is_file( $file ) ) {
                            $zip->addFromString( str_replace( $source . '/', '', $file ), file_get_contents( $file ) );
                        }

						//When we reach the memory limit close the zip archive so the data is written to the hard drive
						if ( memory_get_usage( true ) > $close_limit ) {
							if ( !$zip->close() ) {
								throw new Exception( __( 'error closing zip archive' ) . ' (ERROR_2)' );
							}
							unset( $zip, $file );
                            $zip = null;
						}
                    }
                }
            }
        }
		unset( $zip, $file, $files );
        return true;
    }

    /**
     * Backs up the current WordPress database and saves it to
     * @return string
     */
    public function backup_database() {
        global $wpdb;

        $db_error = __( 'error while accessing database.' );

        $tables = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );
        if ( $tables === false ) {
            throw new Exception( $db_error . ' (ERROR_3)' );
        }

		list( $dump_location, , , ) = $this->get_options();
        $date = date( 'Y-m-d', strtotime( current_time( 'mysql' ) ) );
        $filename = ABSPATH . $dump_location . "/db-backup-$date.sql";
        $handle = fopen( $filename, 'w+' );
        if ( !$handle ) {
            throw new Exception( __( 'error creating sql dump file.' ) . ' (ERROR_4)' );
        }

        //Some header information 
        $this->write_to_file( $handle, "-- WordPress Backup to Dropbox SQL Dump\n" );
        $this->write_to_file( $handle, "-- Version " . BACKUP_TO_DROPBOX_VERSION . "\n" );
        $this->write_to_file( $handle, "-- http://www.mikeyd.com.au/wordpress-backup-to-dropbox/\n" );
        $this->write_to_file( $handle, "-- Generation Time: " . date( "F j, Y" ) . " at " . date( "H:i" ) . "\n\n" );
        $this->write_to_file( $handle, 'SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";' . "\n\n" );

        //I got this out of the phpMyAdmin database dump to make sure charset is correct
        $this->write_to_file( $handle, "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n" );
        $this->write_to_file( $handle, "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n" );
        $this->write_to_file( $handle, "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n" );
        $this->write_to_file( $handle, "/*!40101 SET NAMES utf8 */;\n\n" );

        //Create database statement
        $this->write_to_file( $handle, "--\n-- Create and use the backed up database\n--\n\n" );
        $this->write_to_file( $handle, "CREATE DATABASE " . DB_NAME . ";\n" );
        $this->write_to_file( $handle, "USE " . DB_NAME . ";\n\n" );

        foreach ( $tables as $t ) {
            $table = $t[0];

            //Header comment
            $this->write_to_file( $handle, "--\n-- Table structure for table `$table`\n--\n\n" );

            //Print the create table statement
            $table_create = $wpdb->get_row( "SHOW CREATE TABLE $table", ARRAY_N );
            if ( $table_create === false ) {
                throw new Exception( $db_error . ' (ERROR_5)' );
            }
            $this->write_to_file( $handle, $table_create[1] . ";\n\n" );

            //Print the insert data statements
            $table_data = $wpdb->get_results( "SELECT * FROM $table", ARRAY_A );
            if ( $table_data === false ) {
                throw new Exception( $db_error . ' (ERROR_6)' );
            }

            //Data comment
            $this->write_to_file( $handle, "--\n-- Dumping data for table `$table`\n--\n\n" );

            $fields = '`' . implode( '`, `', array_keys( $table_data[0] ) ) . '`';
            $this->write_to_file( $handle, "INSERT INTO `$table` ($fields) VALUES \n" );

			$out = '';
            foreach ( $table_data as $data ) {
                $data_out = '(';
                foreach ( $data as $value ) {
                    $value = addslashes( $value );
                    $value = str_replace( "\n", "\\n", $value );
                    $value = str_replace( "\r", "\\r", $value );
                    $data_out .= "'$value', ";
                }
                $out .= rtrim( $data_out, ' ,' ) . "),\n";
            }
            $this->write_to_file( $handle, rtrim( $out, ",\n" ) . ";\n" );
        }

        if ( !fclose( $handle ) ) {
            throw new Exception(  __( 'error closing sql dump file.' ) . ' (ERROR_7)'  );
        }

        return $filename;
    }

    /**
     * Write the contents of out to the handle provided. Raise an exception if this fails
     * @throws Exception
     * @param  $handle
     * @param  $out
     * @return void
     */
	private function write_to_file( $handle, $out ) {
        if ( !fwrite( $handle, $out ) ) {
            throw new Exception( __( 'error writing to sql dump file.' ) . ' (ERROR_10)'  );
        }
	}

	/**
	 * Schedules a backup to start now
	 * @return void
	 */
	public function backup_now() {
		wp_schedule_single_event( time(), 'execute_instant_drobox_backup' );
	}

    /**
     * Sets the day, time and frequency a wordpress backup is to be performed
     * @param  $day
     * @param  $time
     * @param  $frequency
     * @return void
     */
    public function set_schedule( $day, $time, $frequency ) {
        $blog_time = strtotime( current_time( 'mysql' ) );

        //Grab the date in the blogs timezone
        $date = date( 'Y-m-d', $blog_time );

        //Check if we need to schedule the backup in the future
        $time_arr = explode( ':', $time );
        $current_day = date( 'D', $blog_time );
        if ( $current_day != $day ) {
            $date = date( 'Y-m-d', strtotime( "next $day" ) );
        } else if ( (int)$time_arr[0] <= (int)date( 'H', $blog_time ) ) {
			$date = date( 'Y-m-d', strtotime( "+7 days" ) );
		}

        $timestamp = wp_next_scheduled( 'execute_periodic_drobox_backup' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'execute_periodic_drobox_backup' );
        }

        //This will be in the blogs timezone
        $scheduled_time = strtotime( $date . ' ' . $time );

        //Convert the selected time to that of the server
        $server_time = time() + ( $scheduled_time - $blog_time );

        wp_schedule_event( $server_time, $frequency, 'execute_periodic_drobox_backup' );
		
        $this->schedule = array( $scheduled_time, $frequency );
    }

    /**
     * Return the backup schedule
     * @return array - day, time, frequency
     */
    public function get_schedule() {
        return $this->schedule;
    }

    /**
     * Set the dropbox backup options
     * @param  $dump_location - Local backup location
     * @param  $dropbox_location - Dropbox backup location,
     * @param  $keep_local - Keep local backups
     * @param  $backup_count - Max backups to keep
     * @return void
     */
    public function set_options( $dump_location, $dropbox_location, $keep_local, $backup_count ) {
        if ( $backup_count < 1 ) {
            $backup_count = 1;
        }

        //The backup count has decreased so we need to purge some of the history
        if ( $this->options[3] > $backup_count ) {
            $diff = $this->options[3] - $backup_count;
            for ( $i = 0; $i < $diff; $i++ ) {
                array_pop( $this->history );
				array_pop( $this->history );
                array_pop( $this->history );
            }
            $this->purge_backups( $diff );
            update_option( 'backup-to-dropbox-history', $this->history );
        }

        $this->options = array( $dump_location, $dropbox_location, $keep_local, $backup_count );
        update_option( 'backup-to-dropbox-options', $this->options );
    }

    /**
     * Removes a number of local backups
     * @param  $count
     * @return void
     */
    public function purge_backups() {
        list( $dump_location, , , $max ) = $this->get_options();
        if ( $max > 0 ) {
            $path = ABSPATH . '/' . $dump_location;
            $dir = opendir( $path );
            $backups = array();
            while ( $file = readdir( $dir ) ) {
                if ( $file == '.' || $file == '..' ) {
                    continue;
                }
                $backups[] = $file;
            }
            asort( $backups );
            $count = count( $backups );
            if ( $count > $max ) {
                $diff = $count - $max;
                for ( $i = 0; $i < $diff; $i++ ) {
                    unlink( $path . '/' . $backups[$i] );
                }
            }
        }
    }

    /**
     * Get the dropbox backup options if we don't have any options set the defaults
     * @return array - Dump location, Dropbox location, Keep local, Backup count
     */
    public function get_options() {
        return $this->options;
    }

    /**
     * Returns the backup history of this wordpress installation
     * @return array - time, success
     */
    public function get_history() {
        return $this->history;
    }

    /**
     * Updates the backup history option
     * @param  $success
     * @param  $msg
     * @return void
     */
    public function set_history( $status, $msg = null ) {
        list( , , , $count ) = $this->get_options();
        //We only want to keep the history of the backups we have stored
        if ( count( $this->history ) >= ( $count * 3 ) ) {
            array_pop( $this->history );
			array_pop( $this->history );
            array_pop( $this->history );
        }
        $this->history[strtotime( current_time( 'mysql' ) )] = array( $status, $msg );
		krsort($this->history);
        update_option( 'backup-to-dropbox-history', $this->history );
    }

    /**
     * Execute the backup
     * @return bool
     */
    public function execute() {//Check that the dump location directory exists
        list( $dump_location, , , ) = $this->get_options();
        $date = date( 'Y-m-d', strtotime( current_time( 'mysql' ) ) );
        $file = "wordpress-backup-$date.zip";
        $dump_dir = ABSPATH . $dump_location;
        $destination = $dump_dir . '/' . $file;

        if ( !file_exists( $dump_dir ) ) {
            if ( !mkdir( $dump_dir ) ) {
                throw new Exception( __( 'error while creating the local dump directory.' ) );
            }
        }

        if ( file_exists( $destination ) ) {
            if ( !unlink( $destination ) ) {
                throw new Exception( sprintf ( __( 'error overwriting backup file %s.' ), $file ) );
            }
        }

        $db_file = $this->backup_database( $destination );
        $ws_success = $this->backup_website( $destination );

        //We can remove the db file because it will be in the backup zip
        if ( $db_file ) {
            unlink( $db_file );
        }

        return ( $ws_success && $db_file ) ? $file : false;
    }
}
