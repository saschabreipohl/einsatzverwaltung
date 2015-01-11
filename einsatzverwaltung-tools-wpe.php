<?php

define('EVW_TOOL_WPE_SLUG', 'einsatzvw-tool-wpe');
define('EVW_TOOL_WPE_DATE_COLUMN', 'Datum');
define('EVW_TOOL_WPE_INPUT_NAME_PREFIX', 'evw_wpe_');


/**
 * Fügt das Werkzeug für wp-einsatz zum Menü hinzu
 */
function einsatzverwaltung_tool_wpe_menu()
{
    add_management_page('wp-einsatz Import', 'wp-einsatz Import', 'manage_options', EVW_TOOL_WPE_SLUG, 'einsatzverwaltung_tool_wpe_page');
}
add_action('admin_menu', 'einsatzverwaltung_tool_wpe_menu');


/**
 * Generiert den Inhalt der Werkzeugseiten
 */
function einsatzverwaltung_tool_wpe_page()
{
    global $wpdb;
    echo '<div class="wrap">';
    echo '<h2>Import von wp-einsatz</h2>';
    
    echo '<p>Dieses Werkzeug importiert Einsätze aus wp-einsatz.</p>';
    
    // Existenz der wp-einsatz Datenbank feststellen
    $tablename = $wpdb->prefix . "einsaetze";
    if ($wpdb->get_var("SHOW TABLES LIKE '$tablename'") != $tablename) {
        einsatzverwaltung_print_error('Die Tabelle, in der wp-einsatz seine Daten speichert, konnte nicht gefunden werden.');
    } else {
        // TODO Formulareingaben mit Nonces absichern
        if (array_key_exists('submit', $_POST) && array_key_exists('aktion', $_POST) && $_POST['aktion'] == 'analyse') {
            // Datenbank analysieren
            echo "<h3>Analyse</h3>";
            echo "<p>Die Daten von wp-einsatz werden analysiert...</p>";
            $felder = einsatzverwaltung_get_wpe_felder($tablename);
            if (empty($felder)) {
                einsatzverwaltung_print_error('Es wurden keine Felder in der Tabelle gefunden');
                return;
            }
            
            einsatzverwaltung_print_success('Es wurden folgende Felder gefunden: ' . implode($felder, ', '));
            
            // Auf Pflichtfelder prüfen
            if (!in_array(EVW_TOOL_WPE_DATE_COLUMN, $felder)) {
                echo '<br>';
                einsatzverwaltung_print_error('Das Feld "'.EVW_TOOL_WPE_DATE_COLUMN.'" konnte nicht in der Datenbank gefunden werden!');
                return;
            }
            
            // Einsätze zählen
            $anzahl_einsaetze = $wpdb->get_var("SELECT COUNT(*) FROM $tablename");
            if ($anzahl_einsaetze === null) {
                einsatzverwaltung_print_warning('Konnte die Anzahl der Ein&auml;tze in wp-einsatz nicht abfragen. M&ouml;glicherweise gibt es ein Problem mit der Datenbank.');
            } else {
                if ($anzahl_einsaetze > 0) {
                    einsatzverwaltung_print_success("Es wurden $anzahl_einsaetze Eins&auml;tze gefunden");
                } else {
                    einsatzverwaltung_print_warning('Es wurden keine Eins&auml;tze gefunden.');
                }
            }
            
            // Felder matchen
            echo "<h3>Felder zuordnen</h3>";
            einsatzverwaltung_form_feldzuordnung($felder);
        } elseif (array_key_exists('submit', $_POST) && array_key_exists('aktion', $_POST) && $_POST['aktion'] == 'import_wpe') {
            echo '<h3>Import</h3>';
            
            $wpe_felder = einsatzverwaltung_get_wpe_felder($tablename);
            if (empty($wpe_felder)) {
                einsatzverwaltung_print_error('Es wurden keine Felder in der Tabelle von wp-einsatz gefunden');
                return;
            }
            
            // nicht zu importierende Felder aussortieren
            $feld_mapping = array();
            foreach ($wpe_felder as $wpe_feld) {
                $index = EVW_TOOL_WPE_INPUT_NAME_PREFIX . strtolower($wpe_feld);
                if (array_key_exists($index, $_POST)) {
                    $evw_feld_name = $_POST[$index];
                    if (!empty($evw_feld_name) && is_string($evw_feld_name) && $evw_feld_name != '-') {
                        if (array_key_exists($evw_feld_name, einsatzverwaltung_get_fields())) {
                            $feld_mapping[$wpe_feld] = $evw_feld_name;
                        } else {
                            einsatzverwaltung_print_warning("Unbekanntes Feld: $evw_feld_name");
                        }
                    }
                }
            }
            $feld_mapping[EVW_TOOL_WPE_DATE_COLUMN] = 'post_date';
            
            // Prüfen, ob mehrere Felder das gleiche Zielfeld haben
            $value_count = array_count_values($feld_mapping);
            foreach ($value_count as $zielfeld => $anzahl) {
                if ($anzahl > 1) {
                    $evw_felder = einsatzverwaltung_get_fields();
                    einsatzverwaltung_print_error("Feld $evw_felder[$zielfeld] kann nur f&uuml;r ein wp-einsatz-Feld als Importziel angegeben werden");
                    einsatzverwaltung_form_feldzuordnung($wpe_felder, $feld_mapping);
                    return;
                }
            }
            
            // Import starten
            echo '<p>Die Daten werden eingelesen, das kann einen Moment dauern.</p>';
            einsatzverwaltung_import_wpe($tablename, $feld_mapping);
        } else {
            einsatzverwaltung_print_success('Die Tabelle, in der wp-einsatz seine Daten speichert, wurde gefunden. Analyse jetzt starten?');
            echo '<form method="post">';
            echo '<input type="hidden" name="aktion" value="analyse" />';
            submit_button('Analyse starten');
            echo '</form>';
        }
    }
}


/**
 * Gibt das Formular für die Zuordnung zwischen zu importieren Feldern und denen von Einsatzverwaltung aus
 */
function einsatzverwaltung_form_feldzuordnung($felder, $mapping = array())
{
    echo '<form method="post">';
    echo '<input type="hidden" name="aktion" value="import_wpe" />';
    echo '<table class="evw_match_fields"><tr><th>Feld in wp-einsatz</th><th>Feld in Einsatzverwaltung</th></tr><tbody>';
    foreach ($felder as $feld) {
        echo '<tr><td><strong>' . $feld . '</strong></td><td>';
        if ($feld == EVW_TOOL_WPE_DATE_COLUMN) {
            echo 'wird automatisch zugeordnet';
        } else {
            // Auf problematische Zeichen prüfen
            if (strpbrk($feld, 'äöüÄÖÜß/#')) {
                einsatzverwaltung_print_warning('Feldname enth&auml;lt Zeichen (z.B. Umlaute oder Sonderzeichen), die beim Import zu Problemen f&uuml;hren.<br>Bitte das Feld in den Einstellungen von wp-einsatz umbenennen, wenn Sie es importieren wollen.');
            } else {
                $selected = '-';
                if(!empty($mapping) && array_key_exists($feld, $mapping) && !empty($mapping[$feld])) {
                    $selected = $mapping[$feld];
                }
                echo einsatzverwaltung_dropdown_eigenefelder(EVW_TOOL_WPE_INPUT_NAME_PREFIX . strtolower($feld), $selected);
            }
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
    submit_button('Import starten');
    echo '</form>';
}


/**
 * Gibt ein Auswahlfeld zur Zuordnung der Felder in Einsatzverwaltung aus
 */
function einsatzverwaltung_dropdown_eigenefelder($name, $selected = '-', $echo = false)
{
    $felder = einsatzverwaltung_get_fields();
    
    // Felder, die automatisch beschrieben werden, nicht zur Auswahl stellen
    unset($felder['post_date']);
    unset($felder['post_name']);
    
    // Sortieren und ausgeben
    asort($felder);
    $string = '';
    $string .= '<select name="' . $name . '">';
    $string .= '<option value="-"' . ($selected == '-' ? ' selected="selected"' : '') . '>nicht importieren</option>';
    foreach ($felder as $slug => $name) {
        $string .= '<option value="' . $slug . '"' . ($selected == $slug ? ' selected="selected"' : '') . '>' . $name . '</option>';
    }
    $string .= '</select>';
        
    if ($echo === true) {
        echo $string;
    } else {
        return $string;
    }
}


/**
 * Gibt die Spaltennamen der wp-einsatz-Tabelle zurück
 * (ohne ID, Nr_Jahr und Nr_Monat)
 */
function einsatzverwaltung_get_wpe_felder($tablename)
{
    global $wpdb;
    $felder = array();
    foreach ($wpdb->get_col("DESC " . $tablename, 0) as $column_name) {
        // Unwichtiges ignorieren
        if ($column_name == 'ID' || $column_name == 'Nr_Jahr' || $column_name == 'Nr_Monat') {
            continue;
        }
        
        $felder[] = $column_name;
    }
    return $felder;
}


/**
 * Importiert Einsätze aus der wp-einsatz-Tabelle
 */
function einsatzverwaltung_import_wpe($tablename, $feld_mapping)
{
    global $wpdb, $evw_meta_fields, $evw_terms, $evw_post_fields;
    
    $query = sprintf('SELECT %s FROM %s ORDER BY %s', implode(array_keys($feld_mapping), ','), $tablename, EVW_TOOL_WPE_DATE_COLUMN);
    $wpe_einsaetze = $wpdb->get_results($query, ARRAY_A);
    
    if ($wpe_einsaetze === null) {
        einsatzverwaltung_print_error('Dieser Fehler sollte nicht auftreten, da hat der Entwickler Mist gebaut...');
        return;
    }
    
    if (empty($wpe_einsaetze)) {
        einsatzverwaltung_print_error('Die Datenbank lieferte keine Ergebnisse. Entweder sind in wp-einsatz keine Eins&auml;tze gespeichert oder es gab ein Problem bei der Abfrage.');
        einsatzverwaltung_print_info('Um ein Problem bei der Abfrage zu vermeiden, entfernen Sie bitte alle Umlaute und Sonderzeichen aus den Feldnamen in wp-einsatz.');
        return;
    }
    
    foreach ($wpe_einsaetze as $wpe_einsatz) {
        $meta_values = array();
        $einsatz_args = array();
        $einsatz_args['post_content'] = '';
        $einsatz_args['tax_input'] = array();
        
        foreach ($feld_mapping as $wpe_feld_name => $evw_feld_name) {
            if (!empty($evw_feld_name) && is_string($evw_feld_name)) {
                if (array_key_exists($evw_feld_name, $evw_meta_fields)) {
                    // Wert gehört in ein Metafeld
                    $meta_values[$evw_feld_name] = $wpe_einsatz[$wpe_feld_name];
                } elseif (array_key_exists($evw_feld_name, $evw_terms)) {
                    // Wert gehört zu einer Taxonomie
                    if (is_taxonomy_hierarchical($evw_feld_name)) {
                        // Bei hierarchischen Taxonomien muss die ID statt des Namens verwendet werden
                        $term = get_term_by('name', $wpe_einsatz[$wpe_feld_name], $evw_feld_name);
                        if ($term === false) {
                            // Term existiert in dieser Taxonomie noch nicht, neu anlegen
                            $newterm = wp_insert_term($wpe_einsatz[$wpe_feld_name], $evw_feld_name);
                            if (is_wp_error($newterm)) {
                                einsatzverwaltung_print_error(
                                    sprintf(
                                        "Konnte %s '%s' nicht anlegen: %s",
                                        $evw_terms[$evw_feld_name],
                                        $wpe_einsatz[$wpe_feld_name],
                                        $newterm->get_error_message()
                                    )
                                );
                            } else {
                                // Anlegen erfolgreich, zurückgegebene ID verwenden
                                $einsatz_args['tax_input'][$evw_feld_name] = $newterm['term_id'];
                            }
                        } else {
                            // Term existiert bereits, ID verwenden
                            $einsatz_args['tax_input'][$evw_feld_name] = $term->term_id;
                        }
                    } else {
                        // Name kann direkt verwendet werden
                        $einsatz_args['tax_input'][$evw_feld_name] = $wpe_einsatz[$wpe_feld_name];
                    }
                } elseif (array_key_exists($evw_feld_name, $evw_post_fields)) {
                    // Wert gehört direkt zum Post
                    $einsatz_args[$evw_feld_name] = $wpe_einsatz[$wpe_feld_name];
                } elseif ($evw_feld_name == '-') {
                    einsatzverwaltung_print_warning("Feld '$wpe_feld_name' nicht zugeordnet");
                } else {
                    einsatzverwaltung_print_error("Feld '$evw_feld_name' unbekannt");
                }
            } else {
                einsatzverwaltung_print_error("Feld '$evw_feld_name' ung&uuml;ltig");
            }
        }
        
        $alarmzeit = date_create($einsatz_args['post_date']);
        $einsatzjahr = date_format($alarmzeit, 'Y');
        $einsatznummer = einsatzverwaltung_get_next_einsatznummer($einsatzjahr, $einsatzjahr == date('Y'));
        $einsatz_args['post_name'] = $einsatznummer;
        $einsatz_args['post_type'] = 'einsatz';
        $einsatz_args['post_status'] = 'publish';
        $einsatz_args['post_date_gmt'] = get_gmt_from_date($einsatz_args['post_date']);
        $meta_values['einsatz_alarmzeit'] = date_format($alarmzeit, 'Y-m-d H:i');
        
        // Titel sicherstellen
        if (!array_key_exists('post_title', $einsatz_args) || empty($einsatz_args['post_title'])) {
            $einsatz_args['post_title'] = 'Einsatz';
        }
        
        // Neuen Beitrag anlegen
        $post_id = wp_insert_post($einsatz_args, true);
        if (is_wp_error($post_id)) {
            einsatzverwaltung_print_error('Konnte Einsatz nicht importieren: ' . $post_id->get_error_message());
        } else {
            einsatzverwaltung_print_info('Einsatz importiert, ID ' . $post_id);
            foreach ($meta_values as $mkey => $mval) {
                update_post_meta($post_id, $mkey, $mval);
            }
            
            // Einsatznummer prüfen
            $gespeicherte_einsatznummer = get_post_field('post_name', $post_id);
            if ($gespeicherte_einsatznummer != $einsatznummer) {
                einsatzverwaltung_print_warning('WordPress hat diesem Einsatz nicht die vorgesehene Einsatznummer erteilt.<br>Verwendung des Werkzeugs <a href="'.admin_url('tools.php?page=einsatzvw-tool-enr').'">Einsatznummern reparieren</a> wird empfohlen.');
            }
        }
    }
    
    einsatzverwaltung_print_success('Der Import ist abgeschlossen');
    echo '<a href="edit.php?post_type=einsatz">Zu den Einsatzberichten</a>';
}
