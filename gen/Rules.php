<?php

declare(strict_types=1);

namespace Centrifugal\Phpcent\Gen;

/**
 * What the generator reads in the protos' comments: the phrases of spec
 * section 2 and 3.3. Kept apart from generate.php for GeneratorTest.
 */
final class Rules
{
    /**
     * Whether the proto marks the field plainly "Required." - the word and
     * its period. "Required unless ..." and "Required when ..." are
     * conditional, and stay optional.
     */
    public static function required(ProtoField $f): bool
    {
        return preg_match('/\bRequired\./', implode(' ', $f->doc)) === 1;
    }

    /**
     * Whether a required field's comment gives its empty value a meaning
     * after "Required." - `"" clears it`, `An empty map clears it.` Such a
     * field is required in the signature but not checked before sending,
     * since empty is one of its values.
     */
    public static function emptyHasMeaning(ProtoField $f): bool
    {
        $doc = implode(' ', $f->doc);
        $at = strpos($doc, 'Required.');
        if ($at === false) {
            return false;
        }
        $after = substr($doc, $at + \strlen('Required.'));

        return str_contains($after, '""') || str_contains(strtolower($after), 'empty');
    }

    /**
     * Whether a message is a presence wrapper, which Centrifugo reads by
     * whether it is there: a message named *Value whose one field is value,
     * as BoolValue, or one whose only field's empty value has a meaning, as
     * device_update's DeviceUserUpdate. A field holding one is optional and
     * sent whenever set, even empty.
     */
    public static function isWrapper(?ProtoMessage $m): bool
    {
        if ($m === null || \count($m->fields) !== 1) {
            return false;
        }
        $f = $m->fields[0];
        if (str_ends_with($m->name, 'Value') && $f->name === 'value') {
            return true;
        }

        return self::required($f) && self::emptyHasMeaning($f);
    }
}
