<?php
require_once 'openid.php';
require_once "duo_config.php";
require_once "duo_web.php";
require_once 'db.php';

if(isset($_GET['openid_identifier']))
{
    if(isset($_GET['csrf_token']))
    {
        if($_SESSION['csrf_token'] != $_GET['csrf_token'])
        {
            throw new Error("csrf","csrf token mismatch!");
        }
    }
    else
    {
        throw new Error("csrf","csrf token missing");
    }
}

try {
    /* STEP 3: Once secondary auth has completed you may log in the user */
    if(isset($_POST['sig_response'])) {
        //verify sig response and log in user
        //make sure that verifyResponse does not return NULL
        //if it is NOT NULL then it will return a username you can then set any cookies/session data for that username and complete the login process
        $oidlogin = Duo::verifyResponse(IKEY, SKEY, AKEY, $_POST['sig_response']);
        if($oidlogin != NULL) {
            // protect against session hijacking now we've escalated privilege level
            session_regenerate_id(true);

            $query = "
                SELECT uid
                FROM users
                WHERE oidlogin='$oidlogin'
                LIMIT 1;
            ";
            $result = do_query($query);
            $row = get_row($result);
            $uid = (string)$row['uid'];

            // store for later
            $_SESSION['oidlogin'] = $oidlogin;
            $_SESSION['uid'] = $uid;

            addlog(LOG_LOGIN, sprintf("  duo login by UID %s (openid %s)", $uid, $oidlogin));
            show_header('login', $uid);
            echo "                    <div class='content_box'>\n";
            echo "                    <h3>" . _("Successful login!") . "</h3>\n";
            echo "                    <p>" . _("Welcome back commander. Welcome back.") . "</p>\n";
        } else {
            show_header('login', 0);
            echo "bad 2nd auth?<br/>\n";
            // throw new Problem(_("Login Error"), "Unable to login.");
        }
    } else {
        $openid = new LightOpenID;
        if (isset($next_page))
            $openid->returnUrl = SITE_URL . "?page=login&next_page=" . preg_replace('/&/', '%26', $next_page);
        if (!$openid->mode) {
            if (isset($_GET['openid_identifier'])) {
                if (isset($_GET['log'])) {
                    $openid->required = array('contact/email');
                    $openid->optional = array('namePerson', 'namePerson/friendly');
                }
                $openid->identity = htmlspecialchars($_GET['openid_identifier'], ENT_QUOTES);
                addlog(LOG_LOGIN, sprintf("  attempt auth for openid %s", $openid->identity));

                if (isset($_GET['remember']))
                    setcookie('openid', $openid->identity, time() + 60*60*24*365);
                else
                    setcookie('openid', FALSE, time() - 60*60*24*365);

                if (isset($_GET['autologin']))
                    setcookie('autologin', TRUE, time() + 60*60*24*365);
                else
                    setcookie('autologin', FALSE, time() - 60*60*24*365);

                header('Location: '.$openid->authUrl());
            } else if (isset($_COOKIE['openid']) && isset($_COOKIE['autologin'])) {
                $openid->identity = $_COOKIE['openid'];
                addlog(LOG_LOGIN, sprintf("  autologin: attempt auth for openid %s", $openid->identity));
                header('Location: '.$openid->authUrl());
            } else
                addlog(LOG_LOGIN, "  showing login form");

            show_header('login', 0);

            $cookie = isset($_COOKIE['openid']) ? $_COOKIE['openid'] : FALSE;
            $autologin = isset($_COOKIE['autologin']);

            echo "<div class='content_box'>\n";
            echo "<h3>" . _("Login") . "</h3>\n";
            echo "<p>" . _("Enter your OpenID login below:") . "</p>\n";
            echo "<p>\n";
            echo "    <form action='' class='indent_form' method='get'>\n";
            echo "        <input type='hidden' name='csrf_token' value='{$_SESSION['csrf_token']}' />\n";
            if (isset($_GET['log'])) {
                $log = "&log";
                echo "        <input type='hidden' name='log' value='1' />\n";
            } else
                $log = "";
            echo "        <input type='text' name='openid_identifier'" . ($cookie ? " value='$cookie'" : "") . " />\n";
            echo "        <input type='checkbox' id='remember' name='remember' value='1'" . ($cookie ? " checked='checked'" : "") . " />\n";
            echo "        <label style='margin: 0px; display: inline;' for='remember'>remember OpenID identifier on this computer</label><br/>\n";
            echo "        <input type='checkbox' id='autologin' name='autologin' value='1'" . ($autologin ? " checked='checked'" : "") . " />\n";
            echo "        <label style='margin: 0px; display: inline;' for='autologin'>automatically log in</label><br/>\n";
            echo "        <input type='hidden' name='page' value='login' /><br/>\n";
            if (isset($next_page)) {
                echo "        <input type='hidden' name='next_page' value='$next_page' /><br/>\n";
                echo "        <input type='hidden' name='oid' value='{$_SESSION['oidlogin']}' /><br/>\n";
            }
            echo "        <input type='submit' value='" . _("Submit") . "' />\n";
            echo "    </form>\n";
            echo "</p>\n";
            echo "<p>" . sprintf(_("We recommend %s."),
                                 "<a href=\"https://www.myopenid.com/\">MyOpenID</a>") . "</p>\n";
            echo "<p>" . sprintf(_("Alternatively you may sign in using %s or %s."),
                                 "<a href=\"?page=login{$log}&openid_identifier=https://www.google.com/accounts/o8/id&csrf_token=" .
                                 $_SESSION['csrf_token'] . "\">Google</a>",
                                 "<a href=\"?page=login{$log}&openid_identifier=me.yahoo.com&csrf_token=" .
                                 $_SESSION['csrf_token'] . "\">Yahoo</a>") . "</p>\n";
        } else if ($openid->mode == 'cancel') {
            setcookie('autologin', FALSE, time() - 60*60*24*365);
            throw new Problem(_("Login Error"), _("Login was cancelled."));
        } else if ($openid->validate()) {
            // protect against session hijacking now we've escalated privilege level
            session_regenerate_id(true);

            $oidlogin = escapestr($openid->identity);
            $use_duo = 0;

            if (isset($_GET['log'])) {
                $attributes = $openid->getAttributes();
                $email = $friendly = $name = '';
                if (isset($attributes['contact/email']))            $email    = $attributes['contact/email'];
                if (isset($attributes['namePerson/friendly']))      $friendly = $attributes['namePerson/friendly'];
                if (isset($attributes['namePerson']))               $name     = $attributes['namePerson'];
                addlog(LOG_LOGIN, "oid: '$oidlogin'; email: '$email'; friendly: '$friendly'; name: '$name'");
            }

            // is this OpenID known to us?
            $query = "
                SELECT uid, use_duo
                FROM users
                WHERE oidlogin='$oidlogin'
                LIMIT 1;
            ";
            $result = do_query($query);

            if (has_results($result)) {
                $row = get_row($result);
                $use_duo = $row['use_duo'];
                $uid = (string)$row['uid'];
            }

            if ($use_duo) {
                addlog(LOG_LOGIN, sprintf("  duo login for UID %s (openid %s)", $uid, $oidlogin));
                show_header('login', 0);
                $sig_request = Duo::signRequest(IKEY, SKEY, AKEY, $oidlogin); ?>
    <script src="js/Duo-Web-v1.bundled.min.js"></script>
    <script>
        Duo.init({'host': <?php echo "'" . HOST . "'"; ?>,
                  'post_action': '?page=login',
                  'sig_request': <?php echo "'" . $sig_request . "'"; ?> });
    </script>
    <iframe id="duo_iframe" width="500" height="800" frameborder="0" allowtransparency="true" style="background: transparent;"></iframe>
<?php
            } else {
                if (has_results($result)) {
                    addlog(LOG_LOGIN, sprintf("  regular login by UID %s (openid %s)", $uid, $oidlogin));
                    if (isset($_GET['next_page'])) {
                        if (!isset($_GET['oid']) ||
                            !isset($_GET['openid_identifier']) ||
                            $_GET['oid'] == $_GET['openid_identifier']) {
                            $_SESSION['last_activity'] = time();
                            $_SESSION['oidlogin'] = $oidlogin;
                            $_SESSION['uid'] = $uid;
                            header('Location: ' . $_GET['next_page']);
                        }
                    }
                    show_header('login', $uid);
                    echo "                    <div class='content_box'>\n";
                    echo "                        <h3>" . _("Successful login!") . "</h3>\n";
                    echo "                        <p>" . _("Welcome back commander. Welcome back.") . "</p>\n";
                } else {
                    addlog(LOG_LOGIN, sprintf("  attempted new signup (openid %s)", $oidlogin));
                    show_header('login', 0);

                    echo "                    <div class='content_box'>\n";
                    echo "                        <h3>" . _("Sorry") . "</h3>\n";
                    echo "                        <p>" . _("Sign-ups are currently disabled.") . "</p>\n";
                    echo "                        <p>" . _("Please log in with your existing account if you have one.") . "</p>\n";
                    return;
                }

                // store for later
                $_SESSION['oidlogin'] = $oidlogin;
                $_SESSION['uid'] = $uid;
            }
        } else {
            setcookie('autologin', FALSE, time() - 60*60*24*365);
            throw new Problem(_("Login Error"), sprintf(_("Unable to login.  Please %stry again%s."),
                                                        '<a href="?page=login">',
                                                        '</a>'));
        }
    }
}
catch (ErrorException $e) {
    setcookie('autologin', FALSE, time() - 60*60*24*365);
    throw new Problem(_("Login Error"), $e->getMessage());
} 
// close content box
?>
                    </div>
