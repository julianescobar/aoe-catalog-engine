<?php
$tests = [
    'â„¢ mojibake' => "â„¢",
    'Â® mojibake'  => "Â®",
    'Ã± mojibake'  => "Ã±",
    'already correct ™' => "™",
    'already correct ®'  => "®",
];

foreach ($tests as $label => $str) {
    echo "$label:\n";
    echo "  input hex: " . bin2hex($str) . "\n";
    
    $w1252 = @mb_convert_encoding($str, 'Windows-1252', 'UTF-8');
    echo "  mb W1252:  " . bin2hex($w1252) . " (valid UTF-8: " . (mb_check_encoding($w1252, 'UTF-8') ? 'yes' : 'no') . ")\n";
    
    $latin1 = @mb_convert_encoding($str, 'ISO-8859-1', 'UTF-8');
    echo "  mb L1:     " . bin2hex($latin1) . " (valid UTF-8: " . (mb_check_encoding($latin1, 'UTF-8') ? 'yes' : 'no') . ")\n";
    
    echo "\n";
}
