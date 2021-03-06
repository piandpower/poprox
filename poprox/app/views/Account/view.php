<?php
use BitsTheater\scenes\Account as MyScene;
/* @var $recite MyScene */
/* @var $v MyScene */
use com\blackmoonit\Widgets;
$recite->includeMyHeader();
$w = '';

$s = $v->getRes('account/msg_pw_nomatch');
//print "<script>function checkPassword(p1,p2) { if (p1.value!=p2.value) {p2.setCustomValidity('\"'+p1.value+'\"!=\"'+p2.value+'\" $s');} else {p2.setCustomValidity('');} }</script>";
print "<script>function checkPassword(p1,p2) { if (p1.value!=p2.value) {p2.setCustomValidity('$s');} else {p2.setCustomValidity('');} }</script>";

$w .= '<h2>Account</h2>';
if (isset($v->err_msg)) {
	$w .= '<span class="msg-error">'.$v->err_msg.'</span>';
} else {
	$w .= $v->renderMyUserMsgsAsString();
}
$w .= '<table class="db-entry">';

//make sure fields will not interfere with any login user/pw field in header
$pwKeyOld = $v->getPwInputKey().'_old';
$pwKeyNew = $v->getPwInputKey().'_new';
//old pw (required to change anything)
$w .= '<tr><td class="db-field-label">'.$v->getRes('account/label_pwinput_old').':</td><td class="db-field">'.
		Widgets::createPassBox($pwKeyOld,$v->$pwKeyOld,true,60,120)."</td></tr>\n";
//username
$w .= '<tr><td class="db-field-label">'.$v->getRes('account/label_name').':</td><td class="db-field">'.
		$v->ticket_info->account_name."</td></tr>\n";
//email
$w .= '<tr><td class="db-field-label">'.$v->getRes('account/label_email').':</td><td class="db-field">'.
		Widgets::createEmailBox('email',$v->ticket_info->email)."</td></tr>\n";
//pw
$w .= '<tr><td class="db-field-label">'.$v->getRes('account/label_pwinput_new').':</td><td class="db-field">'.
		Widgets::createPassBox($pwKeyNew,$v->$pwKeyNew,false,60,120)."</td></tr>\n";
$chkpwJs = "checkPassword(document.getElementById('{$pwKeyNew}'), this);";
$js = "onfocus=\"{$chkpwJs}\" oninput=\"{$chkpwJs}\"";
$w .= '<tr><td class="db-field-label">'.$v->getRes('account/label_pwconfirm').':</td><td class="db-field">'.
		Widgets::createPassBox('password_confirm',$recite->password_confirm,false,60,120,$js)."</td></tr>\n";
//microtask text
$w .= '<tr><td></td><td><em>'.$v->getRes('account/text_reg_cht_microtasking_phone').'</em></td></tr>';
//phone
$w .= '<tr><td class="db-field-label">'.$v->getRes('account/label_phone').':</td><td class="db-field">'.
		Widgets::createTextBox('phone',$v->phone,false)."</td></tr>\n";

//Submit button
$w .= '<tr><td class="db-field-label"></td><td class="db-field">'.
		Widgets::createSubmitButton('button_modify',$v->getRes('account/label_modify'));
		
$w .= "</table>\n";

$w .= Widgets::createHiddenPost('ticket_name',$v->ticket_info->account_name);
$w .= Widgets::createHiddenPost('ticket_email',$v->ticket_info->email);
$w .= Widgets::createHiddenPost('post_key', $v->post_key);

$form_html = Widgets::createHtmlForm($recite->form_name,$recite->action_modify,$w,$v->redirect,false);
print($form_html);
print(str_repeat('<br />',3));
$recite->includeMyFooter();
