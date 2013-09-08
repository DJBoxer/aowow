<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');


require 'includes/class.community.php';

$_id = intVal($pageParam);

$cacheKeyPage    = implode('_', [CACHETYPE_PAGE,    TYPE_SPELL, $_id, -1, User::$localeId]);
$cacheKeyTooltip = implode('_', [CACHETYPE_TOOLTIP, TYPE_SPELL, $_id, -1, User::$localeId]);

// AowowPower-request
if (isset($_GET['power']))
{
    header('Content-type: application/x-javascript; charsetUTF-8');

    Util::powerUseLocale(@$_GET['domain']);

    if (!$smarty->loadCache($cacheKeyTooltip, $x))
    {
        $spell = new SpellList(array(['s.id', $_id]));
        if ($spell->error)
            die('$WowheadPower.registerSpell('.$_id.', '.User::$localeId.', {});');

        $x  = '$WowheadPower.registerSpell('.$_id.', '.User::$localeId.", {\n";
        $pt = [];
        if ($n = $spell->getField('name', true))
            $pt[] = "\tname_".User::$localeString.": '".Util::jsEscape($n)."'";
        if ($i = $spell->getField('iconString'))
            $pt[] = "\ticon: '".urlencode($i)."'";
        if ($tt = $spell->renderTooltip())
        {
            $pt[] = "\ttooltip_".User::$localeString.": '".Util::jsEscape($tt[0])."'";
            $pt[] = "\tspells_".User::$localeString.": ".json_encode($tt[1], JSON_UNESCAPED_UNICODE);
        }
        if ($btt = $spell->renderBuff())
        {
            $pt[] = "\tbuff_".User::$localeString.": '".Util::jsEscape($btt[0])."'";
            $pt[] = "\tbuffspells_".User::$localeString.": ".json_encode($btt[1], JSON_UNESCAPED_UNICODE);;
        }
        $x .= implode(",\n", $pt)."\n});";

        $smarty->saveCache($cacheKeyTooltip, $x);
    }

    die($x);
}

// regular page
if (!$smarty->loadCache($cacheKeyPage, $pageData))
{
    $spell = new SpellList(array(['s.id', $_id]));
    if ($spell->error)
        $smarty->notFound(Lang::$game['spell']);

    $_cat = $spell->getField('typeCat');
    $path = [0, 1, $_cat];

    $l    = [null, 'A', 'B', 'C'];

    // reconstruct path / title
    switch($_cat)
    {
        case  -2:
        case   7:
        case -13:
            $cl = $spell->getField('reqClassMask');
            $i   = 1;

            while ($cl > 0)
            {
                if ($cl & (1 << ($i - 1)))
                {
                    $path[] = $i;
                    break;
                }
                $i++;
            }

            if ($_cat == -13)
            {
                $path[] = ($spell->getField('cuFlags') & (SPELL_CU_GLYPH_MAJOR | SPELL_CU_GLYPH_MINOR)) >> 6;
                break;
            }
        case   9:
        case  -3:
        case  11:
            $path[] = $spell->getField('skillLines')[0];

            if ($_cat == 11)
                if ($_ = $spell->getField('reqSpellId'))
                    $path[] = $_;

            break;
        case -11:
            foreach (SpellList::$skillLines as $line => $skills)
                if (in_array($spell->getField('skillLines')[0], $skills))
                    $path[] = $line;
            break;
        case  -7:                                           // only spells unique in skillLineAbility will always point to the right skillLine :/
            $_ = $spell->getField('cuFlags');
            if ($_ & SPELL_CU_PET_TALENT_TYPE0)
                $path[] = 411;                              // Ferocity
            else if ($_ & SPELL_CU_PET_TALENT_TYPE1)
                $path[] = 409;                              // Tenacity
            else if ($_ & SPELL_CU_PET_TALENT_TYPE2)
                $path[] = 410;                              // Cunning
    }

    // infobox
    $infobox = [];

    if (!in_array($_cat, [-5, -6]))                         // not mount or vanity pet
    {
        if ($_ = $spell->getField('talentLevel'))           // level
            $infobox[] = '[li]'.(in_array($_cat, [-2, 7, -13]) ? sprintf(Lang::$game['reqLevel'], $_) : Lang::$game['level'].Lang::$colon.$_).'[/li]';
        else if ($_ = $spell->getField('spellLevel'))
            $infobox[] = '[li]'.(in_array($_cat, [-2, 7, -13]) ? sprintf(Lang::$game['reqLevel'], $_) : Lang::$game['level'].Lang::$colon.$_).'[/li]';
    }

    if ($mask = $spell->getField('reqRaceMask'))            // race
    {
        $bar = [];
        for ($i = 0; $i < 11; $i++)
            if ($mask & (1 << $i))
                $bar[] = (!fMod(count($bar) + 1, 3) ? '\n' : null) . '[race='.($i + 1).']';

        $t = count($bar) == 1 ? Lang::$game['race'] : Lang::$game['races'];
        $infobox[] = '[li]'.Util::ucFirst($t).Lang::$colon.implode(', ', $bar).'[/li]';
    }

    if ($mask = $spell->getField('reqClassMask'))           // class
    {
        $bar = [];
        for ($i = 0; $i < 11; $i++)
            if ($mask & (1 << $i))
                $bar[] = (!fMod(count($bar) + 1, 3) ? '\n' : null) . '[class='.($i + 1).']';

        $t = count($bar) == 1 ? Lang::$game['class'] : Lang::$game['classes'];
        $infobox[] = '[li]'.Util::ucFirst($t).Lang::$colon.implode(', ', $bar).'[/li]';
    }

    if ($_ = $spell->getField('spellFocusObject'))          // spellFocus
    {
        $bar = DB::Aowow()->selectRow('SELECT * FROM ?_spellFocusObject WHERE id = ?d', $_);
        $infobox[] = '[li]'.Lang::$game['requires2'].' '.Util::localizedString($bar, 'name').'[/li]';
    }

    if (in_array($_cat, [9, 11]))                           // primary & secondary trades
    {
        // skill
        $bar = SkillList::getName($spell->getField('skillLines')[0]);
        if ($_ = $spell->getField('learnedAt'))
            $bar .= ' ('.$_.')';

        $infobox[] = '[li]'.sprintf(Lang::$game['requires'], $bar).'[/li]';

        // specialization
        if ($_ = $spell->getField('reqSpellId'))
            $infobox[] = '[li]'.Lang::$game['requires2'].' '.SpellList::getName($_).'[/li]';

        // difficulty
        if ($_ = $spell->getColorsForCurrent())
        {
            $bar = [];
            for ($i = 0; $i < 4; $i++)
                if ($_[$i])
                    $bar[] = '[color=r'.($i + 1).']'.$_[$i].'[/color]';

            $infobox[] = '[li]'.Lang::$game['difficulty'].Lang::$colon.implode(' ', $bar).'[/li]';
        }
    }

    // flag starter spell
    if (isset($spell->sources[$spell->id]) && array_key_exists(10, $spell->sources[$spell->id]))
        $infobox[] = '[li]'.Lang::$spell['starter'].'[/li]';

    // training cost
    if ($cost = DB::Aowow()->selectCell('SELECT spellcost FROM npc_trainer WHERE spell = ?d', $spell->id))
        $infobox[] = '[li]'.Lang::$spell['trainingCost'].Lang::$colon.'[money='.$cost.'][/li]';

    $pageData = array(
        'title'   => $spell->getField('name', true),
        'path'    => json_encode($path, JSON_NUMERIC_CHECK),
        'infobox' => $infobox,
        'relTabs' => [],
        'view3D'  => 0,
        'page'    => $spell->getDetailPageData()
    );

    // description
    @list(
        $pageData['page']['info'],
        $pageData['page']['spells']
    ) = $spell->renderTooltip(MAX_LEVEL, true);

    // buff
    @list(
        $pageData['page']['buff'],
        $pageData['page']['buffspells']
    ) = $spell->renderBuff(MAX_LEVEL, true);

    // js-globals
    $spell->addGlobalsToJScript($smarty);

    // prepare Tools
    foreach ($pageData['page']['tools'] as $k => $tool)
    {
        if (isset($tool['itemId']))                         // Tool
            $pageData['page']['tools'][$k]['url'] = '?item='.$tool['itemId'];
        else                                                // ToolCat
        {
                $pageData['page']['tools'][$k]['quality'] = ITEM_QUALITY_HEIRLOOM - ITEM_QUALITY_NORMAL;
                $pageData['page']['tools'][$k]['url']     = '?items&filter=cr=91;crs='.$tool['id'].';crv=0';
        }
    }

    // prepare Reagents
    if ($pageData['page']['reagents'])
    {
        $_ = $pageData['page']['reagents'];
        $pageData['page']['reagents'] = [];

        foreach ($spell->relItems->iterate() as $itemId => $__)
        {
            if (empty($_[$itemId]))
                continue;

            $pageData['page']['reagents'][] = array(
                'name'    => $spell->relItems->getField('name', true),
                'quality' => $spell->relItems->getField('quality'),
                'entry'   => $itemId,
                'count'   => $_[$itemId],
            );

            unset($_[$itemId]);

            if (empty($_))
                break;
        }
    }

    // Iterate through all effects:
    $pageData['page']['effect'] = [];

    for ($i = 1; $i < 4; $i++)
    {
        if ($spell->getField('effect'.$i.'Id') <= 0)
            continue;

        $effId   = (int)$spell->getField('effect'.$i.'Id');
        $effMV   = (int)$spell->getField('effect'.$i.'MiscValue');
        $effBP   = (int)$spell->getField('effect'.$i.'BasePoints');
        $effDS   = (int)$spell->getField('effect'.$i.'DieSides');
        $effRPPL =      $spell->getField('effect'.$i.'RealPointsPerLevel');
        $effAura = (int)$spell->getField('effect'.$i.'AuraId');
        $foo     = &$pageData['page']['effect'][];

        // Icons:
        // .. from item
        if ($spell->canCreateItem() && ($_ = $spell->getField('effect'.$i.'CreateItemId')) && $_ > 0)
        {
            foreach ($spell->relItems->iterate() as $itemId => $__)
            {
                if ($itemId != $_)
                    continue;

                $foo['icon'] = array(
                    'id'      => $spell->relItems->id,
                    'name'    => $spell->relItems->getField('name', true),
                    'quality' => $spell->relItems->getField('quality'),
                    'count'   => $effDS + $effBP,
                    'icon'    => $spell->relItems->getField('iconString')
                );
            }

            if ($effDS > 1)
                $foo['icon']['count'] = "'".($effBP + 1).'-'.$foo['icon']['count']."'";
        }
        // .. from spell
        else if (($_ = $spell->getField('effect'.$i.'TriggerSpell')) && $_ > 0)
        {
            $trig = new SpellList(array(['s.id', (int)$_]));

            $foo['icon'] = array(
                'id'    => $_,
                'name'  => $trig->getField('name', true),
                'icon'  => $trig->getField('iconString'),
                'count' => 0
            );

            $trig->addGlobalsToJScript($smarty);
        }

        // Effect Name
        $foo['name'] = '('.$effId.') '.Util::$spellEffectStrings[$effId];

        if ($spell->getField('effect'.$i.'RadiusMax') > 0)
            $foo['radius'] = $spell->getField('effect'.$i.'RadiusMax');

        if (($effBP + $effDS) && !($spell->canCreateItem() && $spell->relItems && !$spell->relItems->error) && (!$spell->getField('effect'.$i.'TriggerSpell') || in_array($effAura, [225, 227])))
            $foo['value'] = ($effDS != 1 ? ($effBP + 1).Lang::$game['valueDelim'] : null).($effBP + $effDS);

        if ($effRPPL != 0)
            $foo['value'] = (isset($foo['value']) ? $foo['value'] : '0') . sprintf(Lang::$spell['costPerLevel'], $effRPPL);

        if($spell->getField('effect'.$i.'Periode') > 0)
            $foo['interval'] = $spell->getField('effect'.$i.'Periode') / 1000;

        // parse masks and indizes
        switch ($effId)
        {
            case 8:                                         // Power Drain
            case 30:                                        // Energize
            case 137:                                       // Energize Pct
                $foo['name'] .= ' ('.sprintf(Util::$dfnString, @Lang::$spell['powerTypes'][$effMV], $effMV).')';
                break;
            case 16:                                        // QuestComplete
                $foo['name'] .= ': <a href="?quest='.$effMV.'">'.QuestList::getName($effMV).'</a> ('.$effMV.')';
                break;
            case 28:                                        // Summon
            case 75:                                        // Summon Totem
            case 87:                                        // Summon Totem (slot 1)
            case 88:                                        // Summon Totem (slot 2)
            case 89:                                        // Summon Totem (slot 3)
            case 90:                                        // Summon Totem (slot 4)
                $summon = new CreatureList(array(['ct.id', $effMV]));

                if (!$pageData['view3D'] && $summon)
                    $pageData['view3D'] = $summon->getRandomModelId();

                $foo['name'] .= ': <a href="?npc='.$effMV.'">'.$summon->getField('name', true).'</a> ('.$effMV.')';
                break;
            case 33:                                        // open Lock
                $foo['name'] .= ' ('.sprintf(Util::$dfnString, @Util::$lockType[$effMV], $effMV).')';
                break;
            case 53:                                        // Enchant Item Perm
            case 54:                                        // Enchant Item Temp
            case 156:                                       // Enchant Item Prismatic
                $_ = DB::Aowow()->selectRow('SELECT * FROM ?_itemEnchantment WHERE id = ?d', $effMV);
                $foo['name'] .= ' <span class="q2">'.Util::localizedString($_, 'text').'</span> ('.$effMV.')';
                break;
            case 38:                                        // Dispel               [miscValue => Types]
            case 126:                                       // Steal Aura
                $foo['name'] .= ' ('.sprintf(Util::$dfnString, @Lang::$game['dt'][$effMV], $effMV).')';
                break;
            case 39:                                        // Learn Language
                $foo['name'] .= ' ('.sprintf(Util::$dfnString, @Lang::$game['languages'][$effMV], $effMV).')';
                break;
            case 50:                                        // Trans Door
            case 76:                                        // Summon Object (Wild)
            case 86:                                        // Activate Object
            case 104:                                       // Summon Object (slot 1)
            case 105:                                       // Summon Object (slot 2)
            case 106:                                       // Summon Object (slot 3)
            case 107:                                       // Summon Object (slot 4)
                // todo (low): create modelviewer-data
                $foo['name'] .= ': <a href="?object='.$effMV.'">'.GameObjectList::getName($effMV).'</a> ('.$effMV.')';
                break;
            case 74:                                        // Apply Glyph
                $_ = DB::Aowow()->selectCell('SELECT spellId FROM ?_glyphProperties WHERE id = ?d', $effMV);
                $foo['name'] .= ': <a href="?spell='.$_.'">'.SpellList::getName($_).'</a> ('.$effMV.')';
                break;
            case 95:                                        // Skinning
                // todo (low): sort this out - 0:skinning (corpse, beast), 1:hearb (GO), 2: mineral (GO), 3: engineer (corpse, mechanic)
                $foo['name'] .= ' ('.sprintf(Util::$dfnString, '[NYI]', $effMV).')';
                break;
            case 108:                                       // Dispel Mechanic
                $foo['name'] .= ' ('.sprintf(Util::$dfnString, @Lang::$game['me'][$effMV], $effMV).')';
                break;
            case 118:                                       // Require Skill
                $foo['name'] .= ': <a href="?skill='.$effMV.'">'.SkillList::getName($effMV).'</a> ('.$effMV.')';
                break;
            case 146:                                       // Activate Rune
                $foo['name'] .= ' ('.sprintf(Util::$dfnString, Lang::$spell['powerRunes'][$effMV], $effMV).')';
                break;
            case 155:                                       // Dual Wield 2H-Weapons
                $foo['name'] .= ': <a href="?spell='.$effMV.'">'.SpellList::getName($effMV).'</a> ('.$effMV.')';
                break;
            default:
            {
                if ($effMV || $effId == 97)
                    $foo['name'] .= ' ('.$effMV.')';
            }
            // Aura
            case 6:                     // Simple
            case 27:                    // AA Persistent
            case 35:                    // AA Party
            case 65:                    // AA Raid
            case 119:                   // AA Pet
            case 128:                   // AA Friend
            case 129:                   // AA Enemy
            case 143:                   // AA Owner
            {
                if ($effAura > 0 && isset(Util::$spellAuraStrings[$effAura]))
                {
                    $foo['name'] .= ' #'.$effAura;
                    switch ($effAura)
                    {
                        case 17:                            // Mod Stealth Detection
                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' ('.sprintf(Util::$dfnString, @Util::$stealthType[$effMV], $effMV).')';
                            break;
                        case 19:                            // Mod Invisibility Detection
                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' ('.sprintf(Util::$dfnString, @Util::$invisibilityType[$effMV], $effMV).')';
                            break;
                        case 24:                            // Periodic Energize
                        case 21:                            // Obsolete Mod Power
                        case 35:                            // Mod Increase Power
                        case 85:                            // Mod Power Regeneration
                        case 110:                           // Mod Power Regeneration Pct
                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' ('.sprintf(Util::$dfnString, @Lang::$spell['powerTypes'][$effMV], $effMV).')';
                            break;
                        case 29:                            // Mod Stat
                        case 80:                            // Mod Stat %
                        case 137:                           // Mod Total Stat %
                        case 175:                           // Mod Spell Healing Of Stat Percent
                        case 212:                           // Mod Ranged Attack Power Of Stat Percent
                        case 219:                           // Mod Mana Regeneration from Stat
                        case 268:                           // Mod Attack Power Of Stat Percent
                            $x = $effMV == -1 ? 0x1F : 1 << $effMV;
                            $bar = [];
                            for ($j = 0; $j < 5; $j++)
                                if ($x & (1 << $j))
                                    $bar[] = Lang::$game['stats'][$j];

                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' ('.sprintf(Util::$dfnString, implode(', ', $bar), $effMV).')';
                            break;
                        case 36:                            // Shapeshift
                            $st = DB::Aowow()->selectRow('SELECT *, displayIdA as model1, displayIdH as model2 FROM ?_shapeshiftForms WHERE id = ?d', $effMV);

                            if ($st['creatureType'] > 0)
                                $pageData['infobox'][] = '[li]'.Lang::$game['type'].Lang::$colon.Lang::$game['ct'][$st['creatureType']].'[/li]';

                            if (!$pageData['view3D'] && $st)
                                $pageData['view3D'] = $st['model2'] ? $st['model'.rand(1,2)]: $st['model1'];

                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' ('.sprintf(Util::$dfnString, Util::localizedString($st, 'name'), $effMV).')';
                            break;
                        case 37:                            // Effect immunity
                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' ('.sprintf(Util::$dfnString, @Util::$spellEffectStrings[$effMV], $effMV).')';
                            break;
                        case 38:                            // Aura immunity
                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' ('.sprintf(Util::$dfnString, @Util::$spellAuraStrings[$effMV], $effMV).')';
                            break;
                        case 41:                            // Dispel Immunity
                        case 178:                           // Mod Debuff Resistance
                        case 245:                           // Mod Aura Duration By Dispel
                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' ('.sprintf(Util::$dfnString, @Lang::$game['dt'][$effMV], $effMV).')';
                            break;
                        case 44:                            // Track Creature
                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' ('.sprintf(Util::$dfnString, @Lang::$game['ct'][$effMV], $effMV).')';
                            break;
                        case 45:                            // Track Resource
                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' ('.sprintf(Util::$dfnString, @Util::$lockType[$effMV], $effMV).')';
                            break;
                        case 75:                            // Language
                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' ('.sprintf(Util::$dfnString, @Lang::$game['languages'][$effMV], $effMV).')';
                            break;
                        case 77:                            // Mechanic Immunity
                        case 117:                           // Mod Mechanic Resistance
                        case 232:                           // Mod Mechanic Duration
                        case 234:                           // Mod Mechanic Duration (no stack)
                        case 255:                           // Mod Mechanic Damage Taken Pct
                        case 276:                           // Mod Mechanic Damage Done Percent
                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' ('.sprintf(Util::$dfnString, @Lang::$game['me'][$effMV], $effMV).')';
                            break;
                        case 147:                           // mechanic Immunity Mask
                            $bar = [];
                            foreach (Lang::$game['me'] as $k => $str)
                                if ($effMV & (1 << $k - 1))
                                    $bar[] = $str;

                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' ('.sprintf(Util::$dfnString, implode(', ', $bar), Util::asHex($effMV)).')';
                            break;
                        case 10:                            // Mod Threat
                        case 13:                            // Mod Damage Done
                        case 14:                            // Mod Damage Taken
                        case 22:                            // Mod Resistance
                        case 39:                            // School Immunity
                        case 40:                            // Damage Immunity
                        case 57:                            // Mod Spell Crit Chance
                        case 69:                            // School Absorb
                        case 71:                            // Mod Spell Crit Chance School
                        case 72:                            // Mod Power Cost School Percent
                        case 73:                            // Mod Power Cost School Flat
                        case 74:                            // Reflect Spell School
                        case 79:                            // Mod Damage Done Pct
                        case 81:                            // Split Damage Pct
                        case 83:                            // Mod Base Resistance
                        case 87:                            // Mod Damage Taken Pct
                        case 97:                            // Mana Shield
                        case 101:                           // Mod Resistance Pct
                        case 115:                           // Mod Healing Taken
                        case 118:                           // Mod Healing Taken Pct
                        case 123:                           // Mod Target Resistance
                        case 135:                           // Mod Healing Done
                        case 136:                           // Mod Healing Done Pct
                        case 142:                           // Mod Base Resistance Pct
                        case 143:                           // Mod Resistance Exclusive
                        case 149:                           // Reduce Pushback
                        case 163:                           // Mod Crit Damage Bonus
                        case 174:                           // Mod Spell Damage Of Stat Percent
                        case 182:                           // Mod Resistance Of Stat Percent
                        case 186:                           // Mod Attacker Spell Hit Chance
                        case 194:                           // Mod Target Absorb School
                        case 195:                           // Mod Target Ability Absorb School
                        case 199:                           // Mod Increases Spell Percent to Hit
                        case 229:                           // Mod AoE Damage Avoidance
                        case 271:                           // Mod Damage Percent Taken Form Caster
                        case 310:                           // Mod Creature AoE Damage Avoidance
                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura];
                            if ($effMV)
                                 $foo['name'] .= ' ('.sprintf(Util::$dfnString, Lang::getMagicSchools($effMV), Util::asHex($effMV)).')';
                            break;
                        case 98:                            // Mod Skill Value
                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' <a href="?skill='.$effMV.'">'.SkillList::getName($effMV).'</a> ('.$effMV.')';
                            break;
                        case 107:                           // Flat Modifier
                        case 108:                           // Pct Modifier
                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' ('.sprintf(Util::$dfnString, Util::$spellModOp[$effMV], $effMV).')';
                            break;
                        case 189:                           // Mod Rating
                        case 220:                           // Combat Rating From Stat
                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' ('.sprintf(Util::$dfnString, Util::$combatRating[log($effMV, 2)], Util::asHex($effMV)).')';
                            break;
                        case 168:                           // Mod Damage Done Versus
                        case 59:                            // Mod Damage Done Versus Creature
                            $bar = [];
                            foreach (Lang::$game['ct'] as $j => $str)
                                if ($effMV & (1 << $j - 1))
                                    $bar[] = $str;

                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' ('.sprintf(Util::$dfnString, implode(', ', $bar), $effMV).')';
                            break;
                        case 249:                           // Convert Rune
                            $x = $spell->getField('effect'.$i.'MiscValueB');
                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' ('.sprintf(Util::$dfnString, Lang::$spell['powerRunes'][$x], $x).')';
                            break;
                        case 78:                            // Mounted
                        case 56:                            // Transform
                        {
                            $transform = new CreatureList(array(['ct.id', $effMV]));

                            if (!$pageData['view3D'] && $transform)
                                $pageData['view3D'] = $transform->getRandomModelId();

                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura].' <a href="?npc='.$effMV.'">'.$transform->getField('name', true).'</a> ('.$effMV.')';
                            break;
                        }
                        default:
                        {
                            $foo['name'] .= Lang::$colon.Util::$spellAuraStrings[$effAura];
                            if ($effMV > 0)
                                $foo['name'] .= ' ('.$effMV.')';
                        }
                    }

                    if (in_array($effAura, [174, 220, 182]))
                        $foo['name'] .= ' ['.sprintf(Util::$dfnString, Lang::$game['stats'][$spell->getField('effect'.$i.'MiscValueB')], $spell->getField('effect'.$i.'MiscValueB')).']';
                    else if ($spell->getField('effect'.$i.'MiscValueB') > 0)
                        $foo['name'] .= ' ['.$spell->getField('effect'.$i.'MiscValueB').']';

                }
                else if ($effAura > 0)
                    $foo['name'] .= ': Unknown Aura ('.$effAura.')';

                break;
            }
        }

        // cases where we dont want 'Value' to be displayed
        if (in_array($effAura, [11, 12, 36, 77]) || in_array($effId, []))
            unset($foo['value']);
    }

    $pageData['infobox'] =  '[ul]'.implode('', $pageData['infobox']).'[/ul]';

    unset($foo);                                            // clear reference

    /*******
    * extra tabs
    *******/

    // tab: modifies $this
    $sub = ['OR'];
    $conditions = [
        ['s.typeCat', [0, -9 /*, -8*/], '!'],               // uncategorized (0), GM (-9), NPC-Spell (-8); NPC includes totems, lightwell and others :/
        ['s.spellFamilyId', $spell->getField('spellFamilyId')],
        &$sub
    ];

    for ($i = 1; $i < 4; $i++)
    {
        // Flat Mods (107), Pct Mods (108), No Reagent Use (256) .. include dummy..? (4)
        if (!in_array($spell->getField('effect'.$i.'AuraId'), [107, 108, 256, 4]))
            continue;

        $m1 = $spell->getField('effect1SpellClassMask'.$l[$i]);
        $m2 = $spell->getField('effect2SpellClassMask'.$l[$i]);
        $m3 = $spell->getField('effect3SpellClassMask'.$l[$i]);

        if (!$m1 && !$m2 && !$m3)
            continue;

        $sub[] = ['s.spellFamilyFlags1', $m1, '&'];
        $sub[] = ['s.spellFamilyFlags2', $m2, '&'];
        $sub[] = ['s.spellFamilyFlags3', $m3, '&'];
    }

    if (count($sub) > 1)
    {
        $modSpells = new SpellList($conditions);
        if (!$modSpells->error)
        {

            if (!$modSpells->hasSetFields(['skillLines']))
                $msH = "$['skill']";

            $pageData['relTabs'][] = array(
                'file'   => 'spell',
                'data'   => $modSpells->getListviewData(),
                'params' => [
                    'tabs'        => '$tabsRelated',
                    'id'          => 'modifies',
                    'name'        => '$LANG.tab_modifies',
                    'visibleCols' => "$['level']",
                    'hiddenCols'  => isset($msH) ? $msH : null
                ]
            );

            $modSpells->addGlobalsToJScript($smarty);
        }
    }

    // tab: modified by $this
    $sub = ['OR'];
    $conditions = [
        ['s.spellFamilyId', $spell->getField('spellFamilyId')],
        &$sub
    ];

    for ($i = 1; $i < 4; $i++)
    {
        $m1 = $spell->getField('spellFamilyFlags1');
        $m2 = $spell->getField('spellFamilyFlags2');
        $m3 = $spell->getField('spellFamilyFlags3');

        if (!$m1 && !$m2 && !$m3)
            continue;

        $sub[] = array(
            'AND',
            ['s.effect'.$i.'AuraId', [107, 108, 256 /*, 4*/]],
            [
                'OR',
                ['s.effect1SpellClassMask'.$l[$i], $m1, '&'],
                ['s.effect2SpellClassMask'.$l[$i], $m2, '&'],
                ['s.effect3SpellClassMask'.$l[$i], $m3, '&']
            ]
        );
    }

    if (count($sub) > 1)
    {
        $modsSpell = new SpellList($conditions);
        if (!$modsSpell->error)
        {
            if (!$modsSpell->hasSetFields(['skillLines']))
                $mbH = "$['skill']";

            $pageData['relTabs'][] = array(
                'file'   => 'spell',
                'data'   => $modsSpell->getListviewData(),
                'params' => [
                    'tabs'        => '$tabsRelated',
                    'id'          => 'modified-by',
                    'name'        => '$LANG.tab_modifiedby',
                    'visibleCols' => "$['level']",
                    'hiddenCols'  => isset($mbH) ? $mbH : null
                ]
            );

            $modsSpell->addGlobalsToJScript($smarty);
        }
    }

    // tab: see also
    $conditions = array(
        ['s.schoolMask', $spell->getField('schoolMask')],
        ['s.effect1Id', $spell->getField('effect1Id')],
        ['s.effect2Id', $spell->getField('effect2Id')],
        ['s.effect3Id', $spell->getField('effect3Id')],
        ['s.id', $spell->id, '!'],
        ['s.name_loc'.User::$localeId, $spell->getField('name', true)]
    );

    $saSpells = new SpellList($conditions);
    if (!$saSpells->error)
    {

        if (!$saSpells->hasSetFields(['skillLines']))
            $saH = "$['skill']";

        $pageData['relTabs'][] = array(
            'file'   => 'spell',
            'data'   => $saSpells->getListviewData(),
            'params' => [
                'tabs'        => '$tabsRelated',
                'id'          => 'see-also',
                'name'        => '$LANG.tab_seealso',
                'visibleCols' => "$['level']",
                'hiddenCols'  => isset($saH) ? $saH : null
            ]
        );

        $saSpells->addGlobalsToJScript($smarty);
    }

    // tab: used by - itemset
    $conditions = array(
        'OR',
        ['spell1', $spell->id], ['spell2', $spell->id], ['spell3', $spell->id], ['spell4', $spell->id],
        ['spell5', $spell->id], ['spell6', $spell->id], ['spell7', $spell->id], ['spell8', $spell->id]
    );

    $ubSets = new ItemsetList($conditions);
    if (!$ubSets->error)
    {
        $pageData['relTabs'][] = array(
            'file'   => 'itemset',
            'data'   => $ubSets->getListviewData(),
            'params' => [
                'tabs' => '$tabsRelated',
                'id'   => 'used-by-itemset',
                'name' => '$LANG.tab_usedby'
            ]
        );

        $ubSets->addGlobalsToJScript($smarty);
    }


    // tab: used by - item
    $conditions = array(
        'OR',                  // 6: learn spell
        ['AND', ['spellTrigger1', 6, '!'], ['spellId1', $spell->id]],
        ['AND', ['spellTrigger2', 6, '!'], ['spellId2', $spell->id]],
        ['AND', ['spellTrigger3', 6, '!'], ['spellId3', $spell->id]],
        ['AND', ['spellTrigger4', 6, '!'], ['spellId4', $spell->id]],
        ['AND', ['spellTrigger5', 6, '!'], ['spellId5', $spell->id]]
    );

    $ubItems = new ItemList($conditions);
    if (!$ubItems->error)
    {
        $pageData['relTabs'][] = array(
            'file'   => 'item',
            'data'   => $ubItems->getListviewData(),
            'params' => [
                'tabs' => '$tabsRelated',
                'id'   => 'used-by-item',
                'name' => '$LANG.tab_usedby'
            ]
        );

        $ubItems->addGlobalsToJScript($smarty);
    }

    // tab: criteria of
    $_ = [ACHIEVEMENT_CRITERIA_TYPE_BE_SPELL_TARGET, ACHIEVEMENT_CRITERIA_TYPE_BE_SPELL_TARGET2, ACHIEVEMENT_CRITERIA_TYPE_CAST_SPELL, ACHIEVEMENT_CRITERIA_TYPE_CAST_SPELL2, ACHIEVEMENT_CRITERIA_TYPE_LEARN_SPELL];
    if ($crs = DB::Aowow()->selectCol('SELECT refAchievement FROM ?_achievementCriteria WHERE type IN (?a) AND value1 = ?d', $_, $spell->id))
    {
        $coAchievemnts = new AchievementList(array(['id', $crs]));
        if (!$coAchievemnts->error)
        {
            $pageData['relTabs'][] = array(
                'data'   => $coAchievemnts->getListviewData(),
                'params' => [
                    'tabs' => '$tabsRelated',
                    'id'   => 'criteria-of',
                    'name' => '$LANG.tab_criteriaof'
                ]
            );

            $coAchievemnts->addGlobalsToJScript($smarty);
        }
    }

    // tab: contains
    // spell_loot_template & skill_extra_item_template
    $extraItem = DB::Aowow()->selectRow('SELECT * FROM skill_extra_item_template WHERE spellid = ?d', $spell->id);
    $spellLoot = DB::Aowow()->select('SELECT *, item as ARRAY_KEY FROM spell_loot_template WHERE entry = ?d', $spell->id);
    if ($extraItem || $spellLoot)
    {
        $ids = [];
        $lv  = [];
        $extraCols = ['Listview.extraCols.percent'];
        foreach ($spellLoot as $row)
            $ids[] = (int)$row['item'];

        if ($ids)
        {
            // todo (high): generic loot-processing function
            $slItems = new ItemList(array(['i.id', $ids]));
            $slItems->addGlobalsToJscript($smarty);
            $lv += $slItems->getListviewData();

            $equal = true;
            foreach ($lv as $k => $v)
            {
                $chance = $spellLoot[$k]['ChanceOrQuestChance'];
                if ($chance)
                    $equal = false;

                $lv[$k]['percent'] = $chance;
                if ($spellLoot[$k]['maxcount'] > 1)
                {
                    $lv[$k]['maxcount'] = $spellLoot[$k]['maxcount'];
                    $lv[$k]['mincount'] = $spellLoot[$k]['mincountOrRef'] > 0 ? $spellLoot[$k]['mincountOrRef'] : 1;
                }
            }

            if ($equal)
                foreach ($lv as &$_)
                    $_['percent'] = number_format(100 / count($lv), 2);
        }

        if ($extraItem && $spell->canCreateItem())
        {
            $foo = $spell->relItems->getListviewData();

            for ($i = 1; $i < 4; $i++)
            {
                if (($bar = $spell->getField('effect'.$i.'CreateItemId')) && isset($foo[$bar]))
                {
                    $lv[$bar] = $foo[$bar];
                    $lv[$bar]['percent']   = $extraItem['additionalCreateChance'];
                    $lv[$bar]['condition'] = ['type' => TYPE_SPELL, 'typeId' => $extraItem['requiredSpecialization'], 'status' => 2];
                    $smarty->extendGlobalIds(TYPE_SPELL, $extraItem['requiredSpecialization']);

                    $extraCols[] = 'Listview.extraCols.condition';
                    if ($max = $extraItem['additionalMaxNum'])
                    {
                        $lv[$bar]['mincount'] = 1;
                        $lv[$bar]['maxcount'] = $max;
                    }

                    break;                                  // skill_extra_item_template can only contain 1 item
                }
            }
        }

        $pageData['relTabs'][] = array(
            'file'   => 'item',
            'data'   => $lv,
            'params' => [
                'tabs'       => '$tabsRelated',
                'name'       => '$LANG.tab_contains',
                'id'         => 'contains',
                'hiddenCols' => "$['side', 'slot', 'source', 'reqlevel']",
                'extraCols'  => "$[".implode(', ', $extraCols)."]"
            ]
        );
    }

    // tab: Exclusive with
    $firstRank = DB::Aowow()->selectCell(                   // returns self or firstRank
       'SELECT      IF(s1.rankId <> 1 AND s2.id, s2.id, s1.id)
        FROM        ?_spell s1
        LEFT JOIN   ?_spell s2
            ON      s1.SpellFamilyId = s2.SpelLFamilyId AND
                    s1.SpellFamilyFlags1 = s2.SpelLFamilyFlags1 AND
                    s1.SpellFamilyFlags2 = s2.SpellFamilyFlags2 AND
                    s1.SpellFamilyFlags3 = s2.SpellFamilyFlags3 AND
                    s1.name_loc0 = s2.name_loc0 AND
                    s2.RankId = 1
        WHERE       s1.id = ?d',
        $_id
    );

    if ($firstRank) {
        $linkedSpells = DB::Aowow()->selectCol(             // dont look too closely ..... please..?
           'SELECT      IF(sg2.spell_id < 0, sg2.id, sg2.spell_id) AS ARRAY_KEY, IF(sg2.spell_id < 0, sg2.spell_id, sr.stack_rule)
            FROM        spell_group sg1
            JOIN        spell_group sg2
                ON      (sg1.id = sg2.id OR sg1.id = -sg2.spell_id) AND sg1.spell_id != sg2.spell_id
            LEFT JOIN   spell_group_stack_rules sr
                ON      sg1.id = sr.group_id
            WHERE       sg1.spell_id = ?d',
            $firstRank
        );
        if ($linkedSpells)
        {
            $extraSpells = [];
            foreach ($linkedSpells as $k => $v)
            {
                if ($v > 0)
                    continue;

                $extraSpells += DB::Aowow()->selectCol(      // recursive case (recursive and regular ids are not mixed in a group)
                   'SELECT      sg2.spell_id AS ARRAY_KEY, sr.stack_rule
                    FROM        spell_group sg1
                    JOIN        spell_group sg2
                        ON      sg2.id = -sg1.spell_id AND sg2.spell_id != ?d
                    LEFT JOIN   spell_group_stack_rules sr
                        ON      sg1.id = sr.group_id
                    WHERE       sg1.id = ?d',
                    $firstRank,
                    $k
                );

                unset($linkedSpells[$k]);
            }

            $groups  = $linkedSpells + $extraSpells;
            $stacks = new SpellList(array(['s.id', array_keys($groups)]));

            if (!$stacks->error)
            {
                $data = $stacks->getListviewData();
                foreach ($data as $k => $d)
                    $data[$k]['stackRule'] = $groups[$k];

                if (!$stacks->hasSetFields(['skillLines']))
                    $sH = "$['skill']";

                $pageData['relTabs'][] = array(
                    'file'   => 'spell',
                    'data'   => $data,
                    'params' => [
                        'tabs'        => '$tabsRelated',
                        'id'          => 'spell-group-stack',
                        'name'        => 'Stack Group',     // todo (med): localize
                        'visibleCols' => "$['stackRules']",
                        'hiddenCols'  => isset($sH) ? $sH : null
                    ]
                );

                $stacks->addGlobalsToJScript($smarty);
            }
        }
    }

    // tab: Linked with
    $rows = DB::Aowow()->select('
        SELECT  spell_trigger AS `trigger`,
                spell_effect AS effect,
                type,
                IF(ABS(spell_effect) = ?d, ABS(spell_trigger), ABS(spell_effect)) AS related
        FROM    spell_linked_spell
        WHERE   ABS(spell_effect) = ?d OR ABS(spell_trigger) = ?d',
        $_id, $_id, $_id
    );

    $related = [];
    foreach ($rows as $row)
        $related[] = $row['related'];

    if ($related)
        $linked = new SpellList(array(['s.id', $related]));

    if (isset($linked) && !$linked->error)
    {
        $lv = $linked->getListviewData();
        $data = [];

        foreach ($rows as $r)
        {
            foreach ($lv as $dk => $d)
            {
                if ($r['related'] == $dk)
                {
                    $lv[$dk]['linked'] = json_encode([$r['trigger'], $r['effect'], $r['type']], JSON_NUMERIC_CHECK);
                    $data[] = $lv[$dk];
                    break;
                }
            }
        }

        $pageData['relTabs'][] = array(
            'file'   => 'spell',
            'data'   => $data,
            'params' => [
                'tabs'        => '$tabsRelated',
                'id'          => 'spell-link',
                'name'        => 'Linked with',             // todo (med): localize
                'hiddenCols'  => "$['skill', 'name']",
                'visibleCols' => "$['linkedTrigger', 'linkedEffect']"
            ]
        );

        $linked->addGlobalsToJScript($smarty);
    }


    // tab: teaches
    // -> spell_learn_spell
    // -> skill_discovery_template

    /* source trainer
        first check source if not trainer :  break

        consult npc_trainer for details

        nyi: CreatureList
        ['taughtbynpc']
    */
    $spellArr['taughtByNpc'] = [];

    /* source item
        first check source if not item :  break

        spellId1 = id OR spellId1 = "LEAR_SPELL_GENERIC" AND spellId2 = id
        spelltrigger_1/2 = 6

    */
    $spellArr['taughtbyitem'] = [];

    // check for taught by spells (Effect 36)
    // find associated NPC, Item and merge results
    // taughtbypets (unused..?)
    // taughtbyquest
    // taughtbytrainers
    // taughtbyitem

    /* used by npc
        first check cat if not npc-spell :  break

        stunt through the tables... >.<

    */
    $spellArr['taughtbyquest'] = [];

    $spellArr['usedbynpc'] = [];

    /* NEW
        scaling data
        conditions
        difficulty-versions
        spell_proc_data

    */

/*

    $questreward = DB::Aowow()->select('
        SELECT c.?#
        { , Title_loc?d AS Title_loc }
        FROM quest_template c
        { LEFT JOIN (locales_quest l) ON c.entry = l.entry AND ? }
        WHERE
            RewSpell = ?d
            OR RewSpellCast = ?d
        ',
        $quest_cols[2],
        (User::$localeId>0)? User::$localeId: DBSIMPLE_SKIP,
        (User::$localeId>0)? 1: DBSIMPLE_SKIP,
        $spellArr['entry'], $spellArr['entry']
    );
    if($questreward)
    {
        $spellArr['questreward'] = [];
        foreach($questreward as $i => $row)
            $spellArr['questreward'][] = GetQuestInfo($row, 0xFFFFFF);
        unset($questreward);
    }
*/

    $smarty->saveCache($cacheKeyPage, $pageData);
}


// menuId 1: Spell    g_initPath()
//  tabId 0: Database g_initHeader()
$smarty->updatePageVars(array(
    'title'  => $pageData['title'].' - '.Util::ucFirst(Lang::$game['spell']),
    'path'   => $pageData['path'],
    'tab'    => 0,
    'type'   => TYPE_SPELL,
    'typeId' => $_id,
    'reqJS'  => array(
        'template/js/swfobject.js'
    )
));
$smarty->assign('community', CommunityContent::getAll(TYPE_SPELL, $_id));         // comments, screenshots, videos
$smarty->assign('lang', array_merge(Lang::$main, Lang::$game, Lang::$spell, ['colon' => Lang::$colon]));
$smarty->assign('lvData', $pageData);

// load the page
$smarty->display('spell.tpl');

?>
