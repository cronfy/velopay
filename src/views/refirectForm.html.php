<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title>Redirecting...</title>
</head>
<body onload="document.forms[0].submit();">
<form action="<?= $url ?>" method="post">
    <p>Redirecting to payment page...</p>
    <p>
        <?php foreach ($data as $name => $value) : ?>
            <input type="hidden" name="<?= htmlentities($name, ENT_QUOTES, 'UTF-8', false) ?>" value="<?= htmlentities($value, ENT_QUOTES, 'UTF-8', false) ?>" />
        <?php endforeach; ?>
        <input type="submit" value="Continue" />
    </p>
</form>
</body>
</html>