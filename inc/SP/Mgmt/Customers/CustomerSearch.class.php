<?php
/**
 * sysPass
 *
 * @author    nuxsmin
 * @link      http://syspass.org
 * @copyright 2012-2016 Rubén Domínguez nuxsmin@$syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace SP\Mgmt\Customers;

defined('APP_ROOT') || die(_('No es posible acceder directamente a este archivo'));

use SP\Mgmt\ItemSearchInterface;
use SP\Storage\DB;
use SP\Storage\QueryData;

/**
 * Class CustomerSearch
 *
 * @package SP\Mgmt\Customers
 */
class CustomerSearch extends CustomerBase implements ItemSearchInterface
{
    /**
     * @param        $limitCount
     * @param int    $limitStart
     * @param string $search
     * @return mixed
     */
    public function getMgmtSearch($limitCount, $limitStart = 0, $search = '')
    {
        $query = 'SELECT customer_id, customer_name, customer_description '
            . 'FROM customers';

        $Data = new QueryData();

        if (!empty($search)) {
            $search = '%' . $search . '%';

            $query .= ' WHERE customer_name LIKE ? '
                . 'OR customer_description LIKE ?';

            $Data->addParam($search);
            $Data->addParam($search);
        }

        $query .= ' ORDER BY customer_name';
        $query .= ' LIMIT ?,?';

        $Data->addParam($limitStart);
        $Data->addParam($limitCount);

        $Data->setQuery($query);

        DB::setReturnArray();
        DB::setFullRowCount();

        $queryRes = DB::getResults($Data);

        if ($queryRes === false) {
            return array();
        }

        $queryRes['count'] = DB::$lastNumRows;

        return $queryRes;
    }
}