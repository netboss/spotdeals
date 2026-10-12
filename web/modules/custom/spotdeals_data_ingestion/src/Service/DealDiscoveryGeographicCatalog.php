<?php

declare(strict_types=1);

namespace Drupal\spotdeals_data_ingestion\Service;

/**
 * Version-controlled US market targets independent of imported venue content.
 *
 * City names are search targets, not assertions that a deal exists there.
 */
final class DealDiscoveryGeographicCatalog {

  /**
   * Returns city/state/country triples for East Coast and Midwest discovery.
   *
   * @return array<int, array{city: string, state: string, country: string}>
   *   Discovery locations.
   */
  public static function locations(): array {
    // Include coastal states, their nearby inland markets, and the Midwest.
    // Keep this list reviewable in Git; additions do not require venue imports.
    $markets = [
      'AL' => 'Birmingham|Huntsville|Mobile|Montgomery|Tuscaloosa|Auburn|Dothan',
      'CT' => 'Bridgeport|Hartford|New Haven|Stamford|Norwalk|Waterbury|New Britain|Danbury|Greenwich|West Hartford',
      'DC' => 'Washington',
      'DE' => 'Wilmington|Dover|Newark|Middletown|Rehoboth Beach',
      'FL' => 'Miami|Fort Lauderdale|West Palm Beach|Fort Myers|Naples|Tampa|St. Petersburg|Clearwater|Sarasota|Orlando|Kissimmee|Daytona Beach|New Smyrna Beach|Jacksonville|St. Augustine|Gainesville|Ocala|Tallahassee|Pensacola|Panama City|Port St. Lucie|Melbourne|Lakeland|Boca Raton|Delray Beach|Cape Coral',
      'GA' => 'Atlanta|Augusta|Savannah|Columbus|Macon|Athens|Albany|Valdosta|Marietta|Roswell|Alpharetta|Sandy Springs|Warner Robins',
      'IA' => 'Des Moines|Cedar Rapids|Davenport|Sioux City|Iowa City|Waterloo|Ames|Council Bluffs|Dubuque|West Des Moines',
      'IL' => 'Chicago|Aurora|Naperville|Joliet|Rockford|Springfield|Peoria|Champaign|Bloomington|Evanston|Schaumburg|Elgin|Waukegan|Oak Park',
      'IN' => 'Indianapolis|Fort Wayne|Evansville|South Bend|Carmel|Fishers|Bloomington|Lafayette|West Lafayette|Muncie|Terre Haute|Gary',
      'KS' => 'Wichita|Overland Park|Kansas City|Olathe|Topeka|Lawrence|Manhattan|Lenexa|Salina|Hutchinson',
      'KY' => 'Louisville|Lexington|Bowling Green|Owensboro|Covington|Richmond|Florence|Paducah|Frankfort',
      'MA' => 'Boston|Cambridge|Worcester|Springfield|Lowell|New Bedford|Fall River|Quincy|Lynn|Salem|Somerville|Plymouth|Brockton|Newton',
      'MD' => 'Baltimore|Frederick|Rockville|Gaithersburg|Annapolis|Bowie|Silver Spring|Bethesda|College Park|Salisbury|Hagerstown|Ellicott City',
      'ME' => 'Portland|Lewiston|Bangor|South Portland|Auburn|Augusta|Biddeford|Brunswick|Bar Harbor',
      'MI' => 'Detroit|Grand Rapids|Warren|Sterling Heights|Ann Arbor|Lansing|Flint|Dearborn|Livonia|Troy|Kalamazoo|Saginaw|Midland|Muskegon|Traverse City',
      'MN' => 'Minneapolis|Saint Paul|Rochester|Duluth|Bloomington|Brooklyn Park|Plymouth|Maple Grove|St. Cloud|Eagan|Eden Prairie|Mankato',
      'MO' => 'Kansas City|St. Louis|Springfield|Columbia|Independence|Lees Summit|O Fallon|St. Joseph|St. Charles|Jefferson City|Joplin',
      'MS' => 'Jackson|Gulfport|Biloxi|Hattiesburg|Southaven|Tupelo|Meridian|Oxford|Olive Branch',
      'NC' => 'Charlotte|Raleigh|Durham|Greensboro|Winston-Salem|Fayetteville|Cary|Wilmington|Asheville|High Point|Concord|Chapel Hill|Burlington|Greenville|Boone',
      'ND' => 'Fargo|Bismarck|Grand Forks|Minot|West Fargo|Williston|Dickinson|Mandan',
      'NE' => 'Omaha|Lincoln|Bellevue|Grand Island|Kearney|Fremont|Norfolk|Hastings|Columbus',
      'NH' => 'Manchester|Nashua|Concord|Derry|Dover|Rochester|Salem|Portsmouth|Keene|Laconia',
      'NJ' => 'Newark|Jersey City|Paterson|Elizabeth|Edison|Woodbridge|Lakewood|Toms River|Trenton|Clifton|Camden|Hoboken|Princeton|Atlantic City|New Brunswick|Morristown',
      'NY' => 'New York|Buffalo|Rochester|Yonkers|Syracuse|Albany|New Rochelle|Mount Vernon|Schenectady|Utica|White Plains|Ithaca|Binghamton|Poughkeepsie|Kingston|Saratoga Springs',
      'OH' => 'Columbus|Cleveland|Cincinnati|Toledo|Akron|Dayton|Parma|Canton|Youngstown|Lorain|Hamilton|Springfield|Kettering|Dublin|Westerville',
      'PA' => 'Philadelphia|Pittsburgh|Allentown|Reading|Erie|Scranton|Bethlehem|Lancaster|Harrisburg|York|State College|Wilkes-Barre|Easton|West Chester',
      'RI' => 'Providence|Warwick|Cranston|Pawtucket|East Providence|Woonsocket|Newport|Westerly',
      'SC' => 'Charleston|Columbia|North Charleston|Mount Pleasant|Rock Hill|Greenville|Spartanburg|Myrtle Beach|Hilton Head Island|Summerville|Florence|Bluffton',
      'SD' => 'Sioux Falls|Rapid City|Aberdeen|Brookings|Watertown|Mitchell|Yankton|Pierre',
      'TN' => 'Nashville|Memphis|Knoxville|Chattanooga|Clarksville|Murfreesboro|Franklin|Jackson|Johnson City|Kingsport|Cleveland',
      'VA' => 'Virginia Beach|Norfolk|Chesapeake|Richmond|Newport News|Alexandria|Hampton|Roanoke|Portsmouth|Suffolk|Lynchburg|Charlottesville|Arlington|Fairfax|Fredericksburg|Winchester',
      'VT' => 'Burlington|South Burlington|Rutland|Barre|Montpelier|St. Albans|Brattleboro|Bennington|Middlebury',
      'WI' => 'Milwaukee|Madison|Green Bay|Kenosha|Racine|Appleton|Waukesha|Oshkosh|Eau Claire|Janesville|Wausau|La Crosse|Sheboygan',
      'WV' => 'Charleston|Huntington|Morgantown|Parkersburg|Wheeling|Weirton|Fairmont|Martinsburg|Beckley',
    ];

    $locations = [];
    foreach ($markets as $state => $cities) {
      foreach (explode('|', $cities) as $city) {
        $locations[] = ['city' => $city, 'state' => $state, 'country' => 'US'];
      }
    }

    return $locations;
  }

}
